<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Spray;
use App\Models\SprayGuest;
use App\Models\SprayTopup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Digital spray.
 *
 * A guest loads a bundle through the payment gateway. The money is the
 * host's from that moment: it's credited to the host's wallet like any cash
 * gift (available the next day). The guest then sprays the bundle note by
 * note; sprays only move the guest's own "sprayed" counter and feed the big
 * screen. Whatever isn't sprayed still belongs to the host and falls as a
 * "final shower" when the host ends spraying. Nothing is ever refunded, so
 * Cherishly never holds guests' money.
 */
class SprayService
{
    public const MIN_TOPUP = 5000;
    public const MAX_TOPUP = 10000000;
    public const NOTES = [200, 500, 1000];
    private const CODE_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private FeeService $fees,
        private GatewayService $gateway,
        private WalletService $wallets,
        private BvnService $bvn,
    ) {}

    /* ── Host ── */

    public function updateSettings(Event $event, array $data): Event
    {
        if (array_key_exists('spray_enabled', $data) && $data['spray_enabled']) {
            if (!$event->spray_code) {
                $data['spray_code'] = $this->newCode();
            }
            $data['spray_ended_at'] = null; // switching it back on reopens it
        }
        $event->update($data);

        return $event->fresh();
    }

    /** End spraying: leftovers fall as one final shower on the screen. */
    public function end(Event $event): Event
    {
        DB::transaction(function () use ($event) {
            $guests = SprayGuest::where('event_id', $event->id)->whereColumn('loaded', '>', 'sprayed')->lockForUpdate()->get();
            foreach ($guests as $guest) {
                $left = $guest->balance();
                if ($left <= 0) {
                    continue;
                }
                Spray::create(['event_id' => $event->id, 'spray_guest_id' => $guest->id, 'amount' => $left,
                    'notes' => max(1, (int) ceil($left / 1000)), 'is_final' => true]);
                $guest->update(['sprayed' => $guest->loaded]);
            }
            $event->update(['spray_enabled' => false, 'spray_ended_at' => now()]);
        });

        return $event->fresh();
    }

    public function hostSummary(Event $event): array
    {
        $paid = SprayTopup::where('event_id', $event->id)->where('payment_status', 'successful');

        return [
            'settings' => $this->settings($event),
            'totals' => [
                'loaded' => (float) (clone $paid)->sum('amount'),
                'received' => (float) (clone $paid)->sum('net_amount'),
                'sprayed' => (float) Spray::where('event_id', $event->id)->sum('amount'),
                'guests' => SprayGuest::where('event_id', $event->id)->where('loaded', '>', 0)->count(),
                'unsprayed' => (float) SprayGuest::where('event_id', $event->id)->sum(DB::raw('loaded - sprayed')),
            ],
            'recent' => Spray::where('event_id', $event->id)->with('guest')->latest('id')->limit(50)->get()
                ->map(fn (Spray $s) => [
                    'id' => $s->id,
                    'guest_id' => $s->spray_guest_id,
                    'name' => $s->guest->screenName(),
                    'amount' => (float) $s->amount,
                    'is_final' => $s->is_final,
                    'message' => $s->guest->message,
                    'message_hidden' => $s->guest->message_hidden,
                    'created_at' => $s->created_at,
                ]),
        ];
    }

    public function settings(Event $event): array
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return [
            'enabled' => (bool) $event->spray_enabled,
            'ended_at' => $event->spray_ended_at,
            'code' => $event->spray_code,
            'show_total' => (bool) $event->spray_show_total,
            'show_leaders' => (bool) $event->spray_show_leaders,
            'guest_url' => $event->spray_code ? "$base/s/{$event->spray_code}" : null,
            'screen_url' => $event->spray_code ? "$base/s/{$event->spray_code}/screen" : null,
            'fee_bearer' => $event->user->fee_bearer ?? 'gifter',
            'fee_rate' => $this->fees->getRate($event->user),
        ];
    }

    /* ── Guest ── */

    public function publicInfo(Event $event): array
    {
        $fee = $this->fees->calculate(100, $event->user);

        return [
            'code' => $event->spray_code,
            'title' => $event->title,
            'event_type' => $event->event_type,
            'event_date' => $event->event_date?->toDateString(),
            'venue' => $event->venue,
            'cover_photo' => $event->cover_photo,
            'host_name' => trim(($event->user->first_name ?? '') . ' ' . ($event->user->last_name ?? '')),
            'open' => $this->isOpen($event),
            'ended' => (bool) $event->spray_ended_at,
            'min_topup' => self::MIN_TOPUP,
            'notes' => self::NOTES,
            'fee_rate' => $fee['fee_rate'],
            'fee_bearer' => $fee['fee_bearer'],
            'bvn_threshold' => $this->bvnThreshold(),
        ];
    }

    public function join(Event $event, array $data): SprayGuest
    {
        $this->assertOpen($event);

        return SprayGuest::create([
            'event_id' => $event->id,
            'token' => Str::random(48),
            'display_name' => ($data['is_anonymous'] ?? false) ? null : trim((string) $data['display_name']),
            'is_anonymous' => (bool) ($data['is_anonymous'] ?? false),
            'message' => isset($data['message']) ? trim((string) $data['message']) ?: null : null,
            'email' => strtolower(trim((string) $data['email'])),
        ]);
    }

    public function guestState(SprayGuest $guest): array
    {
        $this->refreshPending($guest);
        $guest->refresh();

        return [
            'display_name' => $guest->display_name,
            'is_anonymous' => $guest->is_anonymous,
            'message' => $guest->message,
            'email' => $guest->email,
            'loaded' => (float) $guest->loaded,
            'sprayed' => (float) $guest->sprayed,
            'balance' => $guest->balance(),
            'bvn_verified' => (bool) $guest->bvn_verified_at,
            'topups' => $guest->topups()->latest('id')->limit(5)->get(['amount', 'gross_amount', 'payment_reference', 'payment_status', 'created_at'])
                ->map(fn ($t) => [
                    'amount' => (float) $t->amount,
                    'gross_amount' => (float) $t->gross_amount,
                    'reference' => $t->payment_reference,
                    'status' => $t->payment_status,
                    'created_at' => $t->created_at,
                ]),
        ];
    }

    /**
     * Start a top-up. Above the admin's BVN limit (counted per guest per
     * event, and per email so it can't be dodged with a second phone) the
     * guest must verify their BVN first.
     */
    public function startTopup(Event $event, SprayGuest $guest, array $data): array
    {
        $this->assertOpen($event);
        $amount = round((float) $data['amount'], 2);

        $threshold = $this->bvnThreshold();
        if ($threshold > 0 && !$guest->bvn_verified_at) {
            $byEmail = (float) SprayGuest::where('event_id', $event->id)->where('email', $guest->email)->sum('loaded');
            $soFar = max((float) $guest->loaded, $byEmail);
            if ($soFar + $amount > $threshold) {
                if (empty($data['bvn'])) {
                    throw ValidationException::withMessages(['needs_bvn' => [
                        'To load more than ₦' . number_format($threshold) . ' in total, please verify your BVN. It’s a legal requirement for large payments.',
                    ]]);
                }
                $check = $this->bvn->verifyName($data['bvn'], (string) ($data['bvn_first_name'] ?? ''), (string) ($data['bvn_last_name'] ?? ''),
                    ['spray_guest_id' => $guest->id]);
                if (!$check['success']) {
                    throw ValidationException::withMessages(['bvn' => [$check['message']]]);
                }
                $guest->update([
                    'bvn_hash' => BvnService::hash($data['bvn']),
                    'bvn_last4' => substr($data['bvn'], -4),
                    'bvn_full_name' => $check['identity']['full_name'] ?? null,
                    'bvn_reference' => $check['identity']['reference'] ?? null,
                    'bvn_verified_at' => now(),
                ]);
            }
        }

        $fee = $this->fees->calculate($amount, $event->user);
        $reference = 'CHR_SPR_' . $event->id . '_' . strtoupper(Str::random(10));

        $topup = SprayTopup::create([
            'event_id' => $event->id,
            'spray_guest_id' => $guest->id,
            'amount' => $amount,
            'gross_amount' => $fee['gross_amount'],
            'net_amount' => $fee['net_amount'],
            'fee_amount' => $fee['fee_amount'],
            'fee_rate' => $fee['fee_rate'],
            'fee_bearer' => $fee['fee_bearer'],
            'payment_reference' => $reference,
        ]);

        $payment = $this->gateway->initializePayment([
            'email' => $guest->email,
            'name' => $guest->display_name ?: 'Cherishly guest',
            'amount' => $fee['charge_amount'],
            'reference' => $reference,
            'callback_url' => rtrim((string) config('app.frontend_url'), '/') . "/s/{$event->spray_code}/paid",
            // The gateway only knows the existing payment types; spray top-ups
            // ride on monetary_contribution and are told apart by reference.
            'metadata' => ['type' => 'monetary_contribution', 'spray_topup_id' => $topup->id, 'event_id' => $event->id],
        ]);

        if (!$payment['success']) {
            $topup->update(['payment_status' => 'failed']);
            throw new \RuntimeException($payment['message'] ?? 'We couldn’t start the payment. Please try again.');
        }

        return [
            'reference' => $reference,
            'payment_url' => $payment['payment_url'],
            'amount' => $amount,
            'gross_amount' => $fee['gross_amount'],
            'fee_amount' => $fee['fee_amount'],
        ];
    }

    /** Webhook / verify: credit the host once, and add the bundle to the guest. */
    public function confirmTopup(string $reference, string $status, array $meta = []): bool
    {
        return DB::transaction(function () use ($reference, $status, $meta) {
            $topup = SprayTopup::where('payment_reference', $reference)->lockForUpdate()->first();
            if (!$topup) {
                return false;
            }
            if ($topup->payment_status === 'successful') {
                return true; // repeat webhook
            }

            $topup->update(['payment_status' => $status, 'payment_meta' => $meta ?: $topup->payment_meta,
                'paid_at' => $status === 'successful' ? now() : null]);

            if ($status !== 'successful') {
                return true;
            }

            $event = $topup->event()->withTrashed()->first();
            $guest = SprayGuest::whereKey($topup->spray_guest_id)->lockForUpdate()->first();
            $guest->increment('loaded', (float) $topup->amount);

            $wallet = $this->wallets->getWallet($event->user);
            $credit = (float) $topup->net_amount;
            $wallet->increment('balance', $credit);
            $wallet->increment('total_received', $credit);
            $wallet->increment('pending_settlement', $credit);
            $wallet->transactions()->create([
                'user_id' => $wallet->user_id,
                'type' => 'credit',
                'amount' => $credit,
                'description' => "Spray from {$guest->screenName()} at {$event->title}"
                    . ($topup->fee_amount > 0 ? ' (fee: ₦' . number_format((float) $topup->fee_amount, 2) . " paid by {$topup->fee_bearer})" : ''),
                'reference' => $reference,
                'status' => 'successful',
                'available_at' => now()->addDay(), // T+1, like other gifts
            ]);

            return true;
        });
    }

    /**
     * If the webhook is slow, ask the gateway directly about the guest's
     * recent pending top-ups (at most every 5 seconds each).
     */
    public function refreshPending(SprayGuest $guest): void
    {
        $pending = $guest->topups()->where('payment_status', 'pending')
            ->where('created_at', '>', now()->subHours(3))->latest('id')->limit(3)->get();

        foreach ($pending as $topup) {
            if (!Cache::add('spray-verify:' . $topup->id, 1, 5)) {
                continue;
            }
            $result = $this->gateway->verifyPayment($topup->payment_reference);
            $status = strtolower((string) ($result['payment_status'] ?? ''));
            if (in_array($status, ['success', 'successful', 'completed'], true)
                && (float) ($result['amount'] ?? 0) + 1 >= (float) $topup->gross_amount) {
                $this->confirmTopup($topup->payment_reference, 'successful', ['verified_by' => 'poll']);
            } elseif (in_array($status, ['failed', 'abandoned', 'declined', 'reversed'], true)) {
                $this->confirmTopup($topup->payment_reference, 'failed', ['verified_by' => 'poll']);
            }
        }
    }

    /** Spray some notes. Returns the guest's new balance. */
    public function spray(Event $event, SprayGuest $guest, float $amount, int $notes): array
    {
        $this->assertOpen($event);

        return DB::transaction(function () use ($event, $guest, $amount, $notes) {
            $guest = SprayGuest::whereKey($guest->id)->lockForUpdate()->first();
            if ($amount > $guest->balance() + 0.001) {
                throw ValidationException::withMessages(['amount' => ['Not enough left in your bundle. Top up to keep spraying.']]);
            }
            $spray = Spray::create(['event_id' => $event->id, 'spray_guest_id' => $guest->id, 'amount' => $amount, 'notes' => $notes]);
            $guest->increment('sprayed', $amount);

            return ['spray_id' => $spray->id, 'balance' => $guest->balance(), 'sprayed' => (float) $guest->sprayed];
        });
    }

    /* ── Big screen ── */

    public function screen(Event $event, ?int $after): array
    {
        $guests = SprayGuest::where('event_id', $event->id);

        $feedQuery = Spray::where('event_id', $event->id)->with('guest');
        $feed = $after === null
            ? $feedQuery->latest('id')->limit(3)->get()->reverse()->values()
            : $feedQuery->where('id', '>', $after)->orderBy('id')->limit(30)->get();

        $lastId = $after === null
            ? (int) Spray::where('event_id', $event->id)->max('id')
            : max($after, (int) $feed->max('id'));

        return [
            'title' => $event->title,
            'code' => $event->spray_code,
            'open' => $this->isOpen($event),
            'ended' => (bool) $event->spray_ended_at,
            'show_total' => (bool) $event->spray_show_total,
            'show_leaders' => (bool) $event->spray_show_leaders,
            'total' => (float) Spray::where('event_id', $event->id)->sum('amount'),
            'sprayers' => (clone $guests)->where('sprayed', '>', 0)->count(),
            'leaders' => $event->spray_show_leaders
                ? (clone $guests)->where('sprayed', '>', 0)->where('is_anonymous', false)->whereNotNull('display_name')
                    ->orderByDesc('sprayed')->limit(5)->get()
                    ->map(fn ($g) => ['name' => $g->display_name, 'amount' => (float) $g->sprayed])->values()
                : [],
            'feed' => $feed->map(fn (Spray $s) => [
                'id' => $s->id,
                'name' => $s->guest->screenName(),
                'amount' => (float) $s->amount,
                'notes' => $s->notes,
                'message' => $s->guest->message_hidden ? null : $s->guest->message,
                'is_final' => $s->is_final,
            ])->values(),
            'last_id' => $lastId,
        ];
    }

    /* ── Helpers ── */

    public function isOpen(Event $event): bool
    {
        return (bool) $event->spray_enabled && !$event->spray_ended_at && (bool) $event->is_active;
    }

    private function assertOpen(Event $event): void
    {
        if (!$this->isOpen($event)) {
            throw ValidationException::withMessages(['spray' => ['Spraying is closed for this event.']]);
        }
    }

    public function bvnThreshold(): float
    {
        return (float) setting('spray_bvn_threshold', 1000000);
    }

    private function newCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::CODE_CHARS[random_int(0, strlen(self::CODE_CHARS) - 1)];
            }
        } while (Event::withTrashed()->where('spray_code', $code)->exists());

        return $code;
    }
}
