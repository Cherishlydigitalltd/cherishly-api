<?php

namespace App\Services;

use App\Models\AsoebiOrder;
use App\Models\AsoebiPackage;
use App\Models\Event;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Aso-ebi for an event. The coordinator (the event's owner) lists fabric
 * packages; buyers order and pay through /a/<code>; the money goes to the
 * coordinator's wallet like a cash gift; helpers hand each order over once,
 * by scanning its pickup code through the handout link.
 */
class AsoebiService
{
    /** An unpaid order holds its stock this long, so two buyers can't take the last one. */
    private const HOLD_MINUTES = 30;

    public function __construct(
        private FeeService $fees,
        private GatewayService $gateway,
        private WalletService $wallets,
    ) {}

    /* ── Links ──────────────────────────────────── */

    private function frontend(): string
    {
        return rtrim((string) config('app.frontend_url'), '/');
    }

    public function shopUrl(Event $event): ?string
    {
        return $event->asoebi_code ? $this->frontend() . '/a/' . $event->asoebi_code : null;
    }

    public function orderUrl(AsoebiOrder $order): string
    {
        return $this->frontend() . '/aso-ebi/order/' . $order->pickup_token;
    }

    public function handoutUrl(Event $event): ?string
    {
        return $event->asoebi_handout_code ? $this->frontend() . '/a-handout/' . $event->asoebi_handout_code : null;
    }

    private function freshCode(string $column): string
    {
        do {
            $code = collect(range(1, 8))->map(fn () => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'[random_int(0, 31)])->implode('');
        } while (Event::withTrashed()->where($column, $code)->exists());

        return $code;
    }

    /* ── Host settings and packages ─────────────── */

    public function updateSettings(Event $event, array $data): Event
    {
        foreach (['asoebi_deadline', 'asoebi_colours', 'asoebi_pickup_note', 'asoebi_delivery_fee'] as $key) {
            $field = substr($key, 7); // without "asoebi_"
            if (array_key_exists($field, $data)) {
                $event->{$key} = $data[$field] === '' ? null : $data[$field];
            }
        }
        if (array_key_exists('enabled', $data)) {
            $event->asoebi_enabled = (bool) $data['enabled'];
            if ($event->asoebi_enabled && !$event->asoebi_code) {
                $event->asoebi_code = $this->freshCode('asoebi_code');
            }
        }
        if (array_key_exists('handout_enabled', $data) || !empty($data['new_handout_code'])) {
            $event->asoebi_handout_enabled = (bool) ($data['handout_enabled'] ?? $event->asoebi_handout_enabled);
            if ($event->asoebi_handout_enabled && (!$event->asoebi_handout_code || !empty($data['new_handout_code']))) {
                $event->asoebi_handout_code = $this->freshCode('asoebi_handout_code');
            }
        }
        $event->save();

        return $event;
    }

    public function savePackage(Event $event, array $data, ?AsoebiPackage $package = null): AsoebiPackage
    {
        $fields = array_intersect_key($data, array_flip(['name', 'description', 'price', 'stock', 'sort', 'is_active']));
        if ($package) {
            $package->update($fields);
            return $package->fresh();
        }

        return $event->asoebiPackages()->create($fields + ['sort' => ($event->asoebiPackages()->max('sort') ?? 0) + 1]);
    }

    /** A package with orders is switched off rather than deleted, so its orders keep their name. */
    public function removePackage(AsoebiPackage $package): string
    {
        if ($package->orders()->exists()) {
            $package->update(['is_active' => false]);
            return 'hidden';
        }
        $package->delete();

        return 'deleted';
    }

    /* ── Stock and opening ──────────────────────── */

    /** Units sold or held by a payment in progress. */
    private function taken(AsoebiPackage $package): int
    {
        return (int) $package->orders()
            ->where(fn ($q) => $q->where('payment_status', 'successful')
                ->orWhere(fn ($q) => $q->where('payment_status', 'pending')->where('created_at', '>', now()->subMinutes(self::HOLD_MINUTES))))
            ->sum('quantity');
    }

    public function left(AsoebiPackage $package): ?int
    {
        return $package->stock === null ? null : max(0, $package->stock - $this->taken($package));
    }

    public function isOpen(Event $event): bool
    {
        return $event->asoebi_enabled && (!$event->asoebi_deadline || $event->asoebi_deadline->isFuture());
    }

    /* ── Buyers ─────────────────────────────────── */

    public function publicShop(Event $event): array
    {
        $owner = $event->user;

        return [
            'title' => $event->title,
            'event_date' => $event->event_date?->toDateString(),
            'cover_photo' => $event->cover_photo,
            'colours' => $event->asoebi_colours,
            'deadline' => $event->asoebi_deadline,
            'open' => $this->isOpen($event),
            'pickup_note' => $event->asoebi_pickup_note,
            'delivery_fee' => $event->asoebi_delivery_fee !== null ? (float) $event->asoebi_delivery_fee : null,
            'fee_rate' => $owner ? $this->fees->getRate($owner) : 0,
            'fee_bearer' => $owner?->fee_bearer ?? 'gifter',
            'ordered' => (int) $event->asoebiOrders()->where('payment_status', 'successful')->distinct()->count('email'),
            'packages' => $event->asoebiPackages()->where('is_active', true)->get()->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'price' => (float) $p->price,
                'left' => $this->left($p),
            ])->values(),
        ];
    }

    /** @return array{order_token: string, payment_url: string} */
    public function startOrder(Event $event, array $data): array
    {
        if (!$this->isOpen($event)) {
            throw ValidationException::withMessages(['closed' => ['Aso-ebi orders for this event are closed.']]);
        }

        return DB::transaction(function () use ($event, $data) {
            $package = $event->asoebiPackages()->where('is_active', true)->whereKey($data['package_id'])->lockForUpdate()->first();
            if (!$package) {
                throw ValidationException::withMessages(['package_id' => ['That package isn’t available any more.']]);
            }
            $qty = (int) $data['quantity'];
            $left = $this->left($package);
            if ($left !== null && $qty > $left) {
                throw ValidationException::withMessages(['quantity' => [$left > 0
                    ? "Only {$left} left of this package."
                    : 'This package is sold out.']]);
            }

            $delivery = !empty($data['delivery']);
            if ($delivery && $event->asoebi_delivery_fee === null) {
                throw ValidationException::withMessages(['delivery' => ['Delivery isn’t offered for this aso-ebi. Please choose pickup.']]);
            }
            if ($delivery && trim((string) ($data['delivery_address'] ?? '')) === '') {
                throw ValidationException::withMessages(['delivery_address' => ['Enter the address to deliver to.']]);
            }

            $deliveryFee = $delivery ? (float) $event->asoebi_delivery_fee : 0.0;
            $amount = round((float) $package->price * $qty + $deliveryFee, 2);
            $fee = $this->fees->calculate($amount, $event->user);
            $reference = 'CHR_ASO_' . $event->id . '_' . strtoupper(Str::random(10));
            $number = 1000 + (int) $event->asoebiOrders()->count() + 1;

            $order = AsoebiOrder::create([
                'event_id' => $event->id,
                'asoebi_package_id' => $package->id,
                'order_no' => 'AE-' . $number,
                'buyer_name' => trim($data['buyer_name']),
                'email' => strtolower(trim($data['email'])),
                'phone' => $data['phone'] ?? null,
                'quantity' => $qty,
                'unit_price' => $package->price,
                'delivery' => $delivery,
                'delivery_address' => $delivery ? trim($data['delivery_address']) : null,
                'delivery_fee' => $deliveryFee,
                'amount' => $amount,
                'gross_amount' => $fee['gross_amount'],
                'net_amount' => $fee['net_amount'],
                'fee_amount' => $fee['fee_amount'],
                'fee_rate' => $fee['fee_rate'],
                'fee_bearer' => $fee['fee_bearer'],
                'payment_reference' => $reference,
            ]);

            $payment = $this->gateway->initializePayment([
                'email' => $order->email,
                'name' => $order->buyer_name,
                'amount' => $fee['charge_amount'],
                'reference' => $reference,
                'callback_url' => $this->orderUrl($order),
                // The gateway knows only its existing payment types; aso-ebi
                // rides on monetary_contribution and is told apart by reference.
                'metadata' => ['type' => 'monetary_contribution', 'asoebi_order_id' => $order->id, 'event_id' => $event->id],
            ]);

            if (!$payment['success']) {
                $order->update(['payment_status' => 'failed']);
                throw new \RuntimeException($payment['message'] ?? 'We couldn’t start the payment. Please try again.');
            }

            return ['order_token' => $order->pickup_token, 'payment_url' => $payment['payment_url']];
        });
    }

    /** Webhook or poll: mark the order paid (once) and credit the coordinator. */
    public function confirmOrder(string $reference, string $status, array $meta = []): bool
    {
        $paidNow = false;
        $ok = DB::transaction(function () use ($reference, $status, $meta, &$paidNow) {
            $order = AsoebiOrder::where('payment_reference', $reference)->lockForUpdate()->first();
            if (!$order) {
                return false;
            }
            if ($order->payment_status === 'successful') {
                return true; // repeat webhook
            }

            $order->update(['payment_status' => $status, 'payment_meta' => $meta ?: $order->payment_meta,
                'paid_at' => $status === 'successful' ? now() : null]);
            if ($status !== 'successful') {
                return true;
            }

            $event = $order->event()->withTrashed()->first();
            $wallet = $this->wallets->getWallet($event->user);
            $credit = (float) $order->net_amount;
            $wallet->increment('balance', $credit);
            $wallet->increment('total_received', $credit);
            $wallet->increment('pending_settlement', $credit);
            $wallet->transactions()->create([
                'user_id' => $wallet->user_id,
                'type' => 'credit',
                'amount' => $credit,
                'description' => "Aso-ebi {$order->order_no}: {$order->quantity} × {$order->package->name} for {$order->buyer_name}"
                    . ($order->fee_amount > 0 ? ' (fee: ₦' . number_format((float) $order->fee_amount, 2) . " paid by {$order->fee_bearer})" : ''),
                'reference' => $reference,
                'status' => 'successful',
                'available_at' => now()->addDay(),
            ]);
            $paidNow = true;

            return true;
        });

        if ($paidNow) {
            $this->sendReceipt(AsoebiOrder::where('payment_reference', $reference)->first());
        }

        return $ok;
    }

    private function sendReceipt(AsoebiOrder $order): void
    {
        try {
            $order->loadMissing(['package', 'event']);
            Mail::send('emails.asoebi_receipt', ['order' => $order, 'event' => $order->event, 'orderUrl' => $this->orderUrl($order)],
                fn ($m) => $m->to($order->email, $order->buyer_name)->subject("Your aso-ebi for {$order->event->title} is reserved"));
        } catch (\Throwable $e) {
            Log::warning('Aso-ebi receipt not sent', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    /** A buyer back from checkout before the webhook: ask the gateway, at most every 5 seconds. */
    public function refreshPending(AsoebiOrder $order): AsoebiOrder
    {
        if ($order->payment_status !== 'pending' || $order->created_at->lt(now()->subHours(3))
            || !Cache::add('asoebi-verify:' . $order->id, 1, 5)) {
            return $order;
        }

        $result = $this->gateway->verifyPayment($order->payment_reference);
        $status = strtolower((string) ($result['payment_status'] ?? ''));
        if (in_array($status, ['success', 'successful', 'completed'], true)
            && (float) ($result['amount'] ?? 0) + 1 >= (float) $order->gross_amount) {
            $this->confirmOrder($order->payment_reference, 'successful', ['verified_by' => 'poll']);
        } elseif (in_array($status, ['failed', 'abandoned', 'declined', 'reversed'], true)) {
            $this->confirmOrder($order->payment_reference, 'failed', ['verified_by' => 'poll']);
        }

        return $order->fresh();
    }

    /** What the buyer's order page shows. */
    public function orderView(AsoebiOrder $order): array
    {
        $order->loadMissing(['package', 'event']);
        $event = $order->event;

        return [
            'order_no' => $order->order_no,
            'buyer_name' => $order->buyer_name,
            'package' => $order->package->name,
            'quantity' => $order->quantity,
            'delivery' => $order->delivery,
            'delivery_address' => $order->delivery_address,
            'gross_amount' => (float) $order->gross_amount,
            'payment_status' => $order->payment_status,
            'paid_at' => $order->paid_at,
            'handed_over_at' => $order->handed_over_at,
            'order_url' => $this->orderUrl($order),
            'shop_url' => $this->shopUrl($event),
            'event' => [
                'title' => $event->title,
                'event_date' => $event->event_date?->toDateString(),
                'pickup_note' => $event->asoebi_pickup_note,
                'colours' => $event->asoebi_colours,
            ],
        ];
    }

    /* ── Helpers handing out ────────────────────── */

    public function card(AsoebiOrder $order): array
    {
        $order->loadMissing('package');

        return [
            'token' => $order->pickup_token,
            'order_no' => $order->order_no,
            'buyer_name' => $order->buyer_name,
            'phone' => $order->phone,
            'package' => $order->package->name,
            'quantity' => $order->quantity,
            'delivery' => $order->delivery,
            'delivery_address' => $order->delivery_address,
            'payment_status' => $order->payment_status,
            'paid_at' => $order->paid_at,
            'gross_amount' => (float) $order->gross_amount,
            'handed_over_at' => $order->handed_over_at,
            'handed_over_by' => $order->handed_over_by,
        ];
    }

    public function findByScan(Event $event, string $scanned): ?AsoebiOrder
    {
        $scanned = trim($scanned);
        $token = basename(rtrim(parse_url($scanned, PHP_URL_PATH) ?: $scanned, '/'));
        if ($token === '') {
            return null;
        }

        return $event->asoebiOrders()->where('pickup_token', $token)->first()
            ?? $event->asoebiOrders()->where('order_no', strtoupper($token))->where('payment_status', 'successful')->first();
    }

    /** @return array{result: 'handed_over'|'already'|'unpaid', order: AsoebiOrder} */
    public function handOver(AsoebiOrder $order, string $by): array
    {
        if ($order->payment_status !== 'successful') {
            return ['result' => 'unpaid', 'order' => $order];
        }
        $won = AsoebiOrder::whereKey($order->id)->whereNull('handed_over_at')
            ->update(['handed_over_at' => now(), 'handed_over_by' => $by]);

        return ['result' => $won ? 'handed_over' : 'already', 'order' => $order->fresh()];
    }

    public function search(Event $event, string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';

        return $event->asoebiOrders()->where('payment_status', 'successful')
            ->where(fn ($w) => $w->where('buyer_name', 'ilike', $like)->orWhere('order_no', 'ilike', $like)->orWhere('phone', 'ilike', $like))
            ->orderBy('buyer_name')->limit(12)->get()->map(fn ($o) => $this->card($o))->all();
    }

    /* ── The coordinator's dashboard ────────────── */

    public function stats(Event $event): array
    {
        $paid = $event->asoebiOrders()->where('payment_status', 'successful');

        return [
            'money' => (float) (clone $paid)->sum('net_amount'),
            'orders' => (clone $paid)->count(),
            'units' => (int) (clone $paid)->sum('quantity'),
            'handed_over' => (clone $paid)->whereNotNull('handed_over_at')->count(),
            'to_hand_over' => (clone $paid)->whereNull('handed_over_at')->count(),
            'deliveries_open' => (clone $paid)->where('delivery', true)->whereNull('handed_over_at')->count(),
            'unpaid' => $this->unpaidQuery($event)->count(),
        ];
    }

    /** Started but never paid, and no paid order from the same email since. */
    private function unpaidQuery(Event $event)
    {
        return $event->asoebiOrders()->whereIn('payment_status', ['pending', 'failed'])
            ->where('created_at', '<', now()->subMinutes(15))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('asoebi_orders as paid')
                ->whereColumn('paid.event_id', 'asoebi_orders.event_id')
                ->whereColumn('paid.email', 'asoebi_orders.email')
                ->where('paid.payment_status', 'successful')
                ->whereColumn('paid.created_at', '>=', 'asoebi_orders.created_at'));
    }

    public function orders(Event $event, string $filter, int $page = 1): array
    {
        $q = match ($filter) {
            'to_collect' => $event->asoebiOrders()->where('payment_status', 'successful')->whereNull('handed_over_at'),
            'handed_over' => $event->asoebiOrders()->where('payment_status', 'successful')->whereNotNull('handed_over_at'),
            'unpaid' => $this->unpaidQuery($event),
            default => $event->asoebiOrders()->where('payment_status', 'successful'),
        };
        $p = $q->with('package')->latest('id')->paginate(50, ['*'], 'page', $page);

        return [
            'data' => collect($p->items())->map(fn ($o) => ['id' => $o->id, 'email' => $o->email] + $this->card($o))->all(),
            'current_page' => $p->currentPage(),
            'last_page' => $p->lastPage(),
            'total' => $p->total(),
        ];
    }

    public function packagesWithSales(Event $event): array
    {
        return $event->asoebiPackages()->get()->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'price' => (float) $p->price,
            'stock' => $p->stock,
            'is_active' => $p->is_active,
            'sold' => (int) $p->orders()->where('payment_status', 'successful')->sum('quantity'),
            'left' => $this->left($p),
        ])->all();
    }

    /** Email buyers who started an order but didn't pay; once a day each. */
    public function remindUnpaid(Event $event): int
    {
        $shopUrl = $this->shopUrl($event);
        $sent = 0;
        $orders = $this->unpaidQuery($event)->with('package')
            ->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<', now()->subDay()))
            ->orderByDesc('id')->limit(200)->get()->unique('email');

        foreach ($orders as $order) {
            try {
                Mail::send('emails.asoebi_reminder', ['order' => $order, 'event' => $event, 'shopUrl' => $shopUrl],
                    fn ($m) => $m->to($order->email, $order->buyer_name)->subject("Your aso-ebi for {$event->title} isn't paid yet"));
                AsoebiOrder::where('event_id', $event->id)->where('email', $order->email)->whereIn('payment_status', ['pending', 'failed'])
                    ->update(['reminded_at' => now()]);
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Aso-ebi reminder not sent', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /** Paid orders as CSV rows, for the fabric seller and whoever hands out. */
    public function exportRows(Event $event): array
    {
        $rows = [['Order', 'Name', 'Phone', 'Email', 'Package', 'Quantity', 'Getting it', 'Address', 'Paid (₦)', 'Paid at', 'Handed over']];
        $event->asoebiOrders()->where('payment_status', 'successful')->with('package')->orderBy('id')
            ->each(function ($o) use (&$rows) {
                $rows[] = [$o->order_no, $o->buyer_name, $o->phone, $o->email, $o->package->name, $o->quantity,
                    $o->delivery ? 'Delivery' : 'Pickup', $o->delivery_address, number_format((float) $o->gross_amount, 2, '.', ''),
                    $o->paid_at?->format('Y-m-d H:i'), $o->handed_over_at?->format('Y-m-d H:i')];
            });

        return $rows;
    }

    public static function helperName(?string $raw): string
    {
        return CheckInService::usherName($raw);
    }
}
