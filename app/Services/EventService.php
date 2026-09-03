<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventGuest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class EventService
{
    public function __construct(private AssetService $assetService) {}

    /* ── List ── */
    public function list(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return $user->events()->withCount('guests')->latest()->get();
    }

    /* ── Create ── */
    public function create(User $user, array $data): Event
    {
        $coverPhoto = null;
        if (!empty($data['cover_photo']) && $data['cover_photo'] instanceof UploadedFile) {
            $coverPhoto = $this->assetService->upload($data['cover_photo'], 'events');
        }

        return Event::create([
            'user_id'     => $user->id,
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'cover_photo' => $coverPhoto,
            'event_date'  => $data['event_date'] ?? null,
            'venue'       => $data['venue'] ?? null,
            'share_token' => strtoupper(Str::random(16)),
            'is_active'   => true,
        ]);
    }

    /* ── Get guests ── */
    public function getGuests(Event $event, array $filters = []): LengthAwarePaginator
    {
        $query = $event->guests()->latest();

        if (!empty($filters['rsvp_status'])) {
            $query->where('rsvp_status', $filters['rsvp_status']);
        }

        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('full_name', 'like', "%$s%")
                  ->orWhere('email', 'like', "%$s%")
                  ->orWhere('phone', 'like', "%$s%");
            });
        }

        return $query->paginate(20);
    }

    /* ── Add guests manually ── */
    public function addGuests(Event $event, array $guests): array
    {
        $created = [];
        foreach ($guests as $g) {
            $created[] = EventGuest::create([
                'event_id'       => $event->id,
                'full_name'      => $g['full_name'],
                'email'          => $g['email'] ?? null,
                'phone'          => $g['phone'] ?? null,
                'allow_plus_one' => $g['allow_plus_one'] ?? false,
                'qr_token'       => strtoupper(Str::random(16)),
            ]);
        }
        return $created;
    }

    /* ── Import from Excel ── */
    public function importGuests(Event $event, UploadedFile $file): array
    {
        $rows = array_map('str_getcsv', file($file->getRealPath()));
        $header = array_shift($rows);
        $created = [];

        foreach ($rows as $row) {
            if (empty($row[0])) continue;
            $created[] = EventGuest::create([
                'event_id'  => $event->id,
                'full_name' => $row[0] ?? '',
                'email'     => $row[1] ?? null,
                'phone'     => $row[2] ?? null,
                'qr_token'  => strtoupper(Str::random(16)),
            ]);
        }

        return $created;
    }

    /* ── Check in guest ── */
    public function checkIn(EventGuest $guest): EventGuest
    {
        $guest->update(['checked_in_at' => now()]);
        return $guest->fresh();
    }

    /* ── Check in by QR token ── */
    public function checkInByQrToken(string $qrToken): ?EventGuest
    {
        $guest = EventGuest::where('qr_token', $qrToken)->first();
        if (!$guest) return null;
        return $this->checkIn($guest);
    }

    /* ── Attendance stats ── */
    public function attendance(Event $event): array
    {
        $total     = $event->guests()->count();
        $checkedIn = $event->guests()->whereNotNull('checked_in_at')->count();
        $remaining = $total - $checkedIn;
        $rate      = $total > 0 ? round(($checkedIn / $total) * 100) : 0;

        $recentCheckIns = $event->guests()
            ->whereNotNull('checked_in_at')
            ->orderByDesc('checked_in_at')
            ->take(20)
            ->get(['full_name', 'checked_in_at']);

        return [
            'total'          => $total,
            'checked_in'     => $checkedIn,
            'remaining'      => $remaining,
            'attendance_rate'=> $rate,
            'recent_checkins'=> $recentCheckIns,
        ];
    }

    /* ── Find by share token ── */
    public function findByToken(string $token): ?Event
    {
        return Event::where('share_token', $token)->where('is_active', true)->first();
    }
}
