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
        // Read CSV/Excel file
        $guests = [];
        $handle = fopen($file->getRealPath(), 'r');
        $header = fgetcsv($handle); // skip header row

        while (($row = fgetcsv($handle)) !== false) {
            if (empty($row[0]))
                continue;

            $guests[] = InvitationGuest::create([
                'invitation_id' => $invitation->id,
                'full_name' => $row[0] ?? '',
                'email' => $row[1] ?? null,
                'phone' => $row[2] ?? null,
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

        return $guests->count();
    }

    /* ── Public: get by token ── */

    public function findByToken(string $token): ?Invitation
    {
        return Invitation::where('share_token', $token)->first();
    }

    /* ── Public: get guest by QR token ── */

    public function findGuestByQrToken(string $qrToken): ?InvitationGuest
    {
        return InvitationGuest::where('qr_token', $qrToken)->first();
    }

    /* ── Public: RSVP ── */

    public function rsvp(InvitationGuest $guest, string $status, bool $hasPlusOne = false): InvitationGuest
    {
        $guest->update([
            'rsvp_status' => $status,
            'has_plus_one' => $hasPlusOne,
            'rsvp_responded_at' => now(),
        ]);

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
