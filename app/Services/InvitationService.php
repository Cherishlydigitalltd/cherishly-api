<?php

namespace App\Services;

use App\Models\Invitation;
use App\Models\InvitationGuest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InvitationService
{
    public function __construct(
        private AssetService $assetService
    ) {
    }

    /* ── List ── */

    public function getUserInvitations(User $user): LengthAwarePaginator
    {
        return Invitation::where('user_id', $user->id)
            ->withCount('guests')
            ->latest()
            ->paginate(20);
    }

    /* ── Create ── */

    public function create(User $user, array $data): Invitation
    {
        $coverPhotoUrl = null;

        if (!empty($data['cover_photo']) && $data['cover_photo'] instanceof UploadedFile) {
            $coverPhotoUrl = $this->assetService->upload($data['cover_photo'], 'invitations');
        }

        return Invitation::create([
            'user_id' => $user->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'cover_photo' => $coverPhotoUrl,
            'rsvp_deadline' => $data['rsvp_deadline'] ?? null,
            'scheduled_at' => $data['scheduled_at'] ?? null,
        ]);
    }

    /* ── Update ── */

    public function update(Invitation $invitation, array $data): Invitation
    {
        if (!empty($data['cover_photo']) && $data['cover_photo'] instanceof UploadedFile) {
            $data['cover_photo'] = $this->assetService->replace(
                $invitation->cover_photo,
                $data['cover_photo'],
                'invitations'
            );
        } else {
            unset($data['cover_photo']);
        }

        $invitation->update($data);
        return $invitation->fresh();
    }

    /* ── Delete ── */

    public function delete(Invitation $invitation): void
    {
        if ($invitation->cover_photo) {
            $this->assetService->delete($invitation->cover_photo);
        }
        $invitation->delete();
    }

    /* ── Add guests (single or bulk) ── */

    public function addGuests(Invitation $invitation, array $guests): array
    {
        $created = [];

        foreach ($guests as $guest) {
            $created[] = InvitationGuest::create([
                'invitation_id' => $invitation->id,
                'full_name' => $guest['full_name'],
                'email' => $guest['email'] ?? null,
                'phone' => $guest['phone'] ?? null,
                'allow_plus_one' => $guest['allow_plus_one'] ?? false,
            ]);
        }

        return $created;
    }

    /* ── Upload guests from Excel ── */

    public function importGuests(Invitation $invitation, UploadedFile $file): array
    {
        // Read CSV. The dashboard's template is "First Name, Last Name,
        // Email, Phone"; older files are "Full Name, Email, Phone". Use the
        // header row to tell them apart instead of assuming column order.
        $guests = [];
        $handle = fopen($file->getRealPath(), 'r');
        $header = array_map(
            fn ($h) => strtolower(trim((string) $h)),
            fgetcsv($handle) ?: []
        );
        $col = fn (array $names) => collect($names)
            ->map(fn ($n) => array_search($n, $header, true))
            ->first(fn ($i) => $i !== false);

        $first = $col(['first name', 'first_name', 'firstname']);
        $last = $col(['last name', 'last_name', 'lastname', 'surname']);
        $full = $col(['full name', 'full_name', 'name']);
        $email = $col(['email', 'email address']) ?? ($first !== null ? 2 : 1);
        $phone = $col(['phone', 'phone number', 'phone_number']) ?? ($first !== null ? 3 : 2);

        while (($row = fgetcsv($handle)) !== false) {
            $name = $first !== null
                ? trim(($row[$first] ?? '') . ' ' . ($last !== null ? ($row[$last] ?? '') : ''))
                : trim($row[$full ?? 0] ?? '');

            if ($name === '')
                continue;

            $guests[] = InvitationGuest::create([
                'invitation_id' => $invitation->id,
                'full_name' => $name,
                'email' => trim($row[$email] ?? '') ?: null,
                'phone' => trim($row[$phone] ?? '') ?: null,
            ]);
        }

        fclose($handle);

        return $guests;
    }

    /* ── Get guests (paginated) ── */

    public function getGuests(Invitation $invitation, array $filters = []): LengthAwarePaginator
    {
        $query = $invitation->guests()->latest();

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        if (!empty($filters['rsvp_status'])) {
            $query->where('rsvp_status', $filters['rsvp_status']);
        }

        return $query->paginate(10);
    }

    /* ── Remove guest ── */

    public function removeGuest(InvitationGuest $guest): void
    {
        $guest->delete();
    }

    /* ── Send invitations ── */

    public function sendInvitations(Invitation $invitation): int
    {
        $guests = $invitation->guests()
            ->whereNotNull('email')
            ->get();

        foreach ($guests as $guest) {
            // Dispatch email job
            \App\Jobs\SendInvitationEmail::dispatch($guest, $invitation);
        }

        $invitation->update(['sent_at' => now(), 'scheduled_at' => null]);

        return $guests->count();
    }

    /* ── Send scheduled invitations that are due (run by the scheduler) ── */

    public function sendDueScheduled(): int
    {
        $due = Invitation::whereNotNull('scheduled_at')
            ->whereNull('sent_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($due as $invitation) {
            $count = $this->sendInvitations($invitation);

            // The host wasn't there when it went out, so let them know.
            app(NotificationService::class)->create(
                $invitation->user,
                'invitation',
                'Scheduled invitations sent',
                "{$invitation->title} went out to {$count} " . ($count === 1 ? 'guest' : 'guests') . '.',
                "/dashboard/invitations/{$invitation->id}",
                '📨'
            );
        }

        return $due->count();
    }

    /* ── Public: get by token ── */

    public function findByToken(string $token): ?Invitation
    {
        return Invitation::where('share_token', $token)->first();
    }

    /* ── Public: get guest by their RSVP link code ── */

    public function findGuestByRsvpToken(Invitation $invitation, ?string $code): ?InvitationGuest
    {
        // Numeric ids from old links are refused on purpose: they could be guessed.
        if (!$code || strlen($code) < 20) {
            return null;
        }

        return $invitation->guests()->where('rsvp_token', $code)->first();
    }

    /* ── Public: get guest by QR token ── */

    public function findGuestByQrToken(string $qrToken): ?InvitationGuest
    {
        return InvitationGuest::where('qr_token', $qrToken)->first();
    }

    /* ── Public: RSVP ── */

    public function rsvp(InvitationGuest $guest, string $status, bool $hasPlusOne = false): InvitationGuest
    {
        $wasAttending = $guest->rsvp_status === 'attending';
        $previous = $guest->rsvp_status;

        $guest->update([
            'rsvp_status' => $status,
            'has_plus_one' => $hasPlusOne,
            'rsvp_responded_at' => now(),
        ]);

        // Tell the host — only when the answer actually changes, so a guest
        // re-opening their link and pressing the same button stays quiet.
        if ($status !== $previous) {
            $invitation = $guest->invitation;
            $plusOne = $status === 'attending' && $hasPlusOne ? ' (+1)' : '';
            app(NotificationService::class)->create(
                $invitation->user,
                'rsvp',
                $status === 'attending' ? 'New RSVP: attending' : 'New RSVP: can’t make it',
                $status === 'attending'
                    ? "{$guest->full_name}{$plusOne} is coming to {$invitation->title}."
                    : "{$guest->full_name} can't make it to {$invitation->title}.",
                "/dashboard/invitations/{$invitation->id}",
                $status === 'attending' ? '🎉' : '✉️'
            );
        }

        // Email the guest their entry pass the first time they say yes.
        if ($status === 'attending' && !$wasAttending && $guest->email) {
            \App\Jobs\SendInvitationPassEmail::dispatch($guest->fresh(), $guest->invitation);
        }

        return $guest->fresh();
    }

    /* ── Check in guest ── */

    public function checkIn(InvitationGuest $guest): InvitationGuest
    {
        $guest->update([
            'checked_in' => true,
            'checked_in_at' => now(),
        ]);

        return $guest->fresh();
    }

    /* ── Check in by scanned pass ── */

    /**
     * $code is whatever the scanner read: the pass URL or the bare token.
     * Only guests of this invitation match, so a pass for another event
     * (or another host's event) is rejected rather than checked in.
     *
     * @return array{result: 'checked_in'|'already_checked_in'|'not_found', guest: ?InvitationGuest}
     */
    public function checkInByCode(Invitation $invitation, string $code): array
    {
        $token = basename(parse_url(trim($code), PHP_URL_PATH) ?: trim($code));

        $guest = $token === ''
            ? null
            : $invitation->guests()->where('qr_token', $token)->first();

        if (!$guest) {
            return ['result' => 'not_found', 'guest' => null];
        }

        if ($guest->checked_in) {
            return ['result' => 'already_checked_in', 'guest' => $guest];
        }

        return ['result' => 'checked_in', 'guest' => $this->checkIn($guest)];
    }

    /* ── Real-time attendance ── */

    public function getAttendance(Invitation $invitation): array
    {
        $total = $invitation->guests()->where('rsvp_status', 'attending')->count();
        $checkedIn = $invitation->guests()->where('checked_in', true)->count();
        $remaining = max(0, $total - $checkedIn);
        $rate = $total > 0 ? round(($checkedIn / $total) * 100) : 0;

        $recentCheckIns = $invitation->guests()
            ->where('checked_in', true)
            ->orderByDesc('checked_in_at')
            ->take(20)
            ->get(['full_name', 'checked_in_at']);

        return [
            'checked_in' => $checkedIn,
            'remaining' => $remaining,
            'attendance_rate' => $rate,
            'recent_checkins' => $recentCheckIns,
        ];
    }
}
