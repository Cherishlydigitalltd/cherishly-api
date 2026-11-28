<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Invitation;
use App\Models\InvitationGuest;
use Illuminate\Support\Str;

/**
 * QR check-in at the venue. Ushers open the invitation's door link
 * (/door/<code>), scan a guest's pass, see the table and party size, check
 * the party in and hand over souvenirs. Both steps are one-time: a second
 * scan says when, and by whom, it was already done.
 */
class CheckInService
{
    /** People this guest's pass covers: the host's figure, else 1 (+1). */
    private const PARTY_SQL = 'COALESCE(party_size, CASE WHEN has_plus_one THEN 2 ELSE 1 END)';

    public static function partySize(InvitationGuest $guest): int
    {
        return (int) ($guest->party_size ?: ($guest->has_plus_one ? 2 : 1));
    }

    /** A guest as ushers and the host see them (never their email or phone). */
    public function card(InvitationGuest $guest): array
    {
        return [
            'qr_token' => $guest->qr_token,
            'full_name' => $guest->full_name,
            'table' => $guest->table_label,
            'party_size' => self::partySize($guest),
            'rsvp_status' => $guest->rsvp_status,
            'checked_in' => (bool) $guest->checked_in,
            'checked_in_at' => $guest->checked_in_at,
            'checked_in_by' => $guest->checked_in_by,
            'souvenirs_at' => $guest->souvenirs_at,
            'souvenirs_by' => $guest->souvenirs_by,
        ];
    }

    /** The event page linked to this invitation, for the date and venue. */
    public function eventFor(Invitation $invitation): ?Event
    {
        return Event::where('invitation_id', $invitation->id)->latest('id')->first();
    }

    public function eventInfo(Invitation $invitation): array
    {
        $event = $this->eventFor($invitation);

        return [
            'title' => $event?->title ?: $invitation->title,
            'date' => $event?->event_date?->toDateString(),
            'start_time' => $event?->start_time,
            'venue' => $event?->venue,
            'venue_address' => $event?->venue_address,
            'map_link' => $event?->map_link,
            'cover_photo' => $event?->cover_photo ?: $invitation->cover_photo,
        ];
    }

    /** What a scanner reads: a pass URL (/pass/<token>) or the bare token. */
    public function tokenFrom(string $scanned): string
    {
        $scanned = trim($scanned);
        $path = parse_url($scanned, PHP_URL_PATH) ?: $scanned;

        return basename(rtrim($path, '/'));
    }

    public function findGuest(Invitation $invitation, string $scanned): ?InvitationGuest
    {
        $token = $this->tokenFrom($scanned);

        return $token === '' ? null : $invitation->guests()->where('qr_token', $token)->first();
    }

    /**
     * Check the party in, and hand over souvenirs at the same time if asked.
     * Conditional updates, so two ushers scanning together can't both win.
     *
     * @return array{result: 'checked_in'|'already_checked_in', guest: InvitationGuest}
     */
    public function checkIn(Invitation $invitation, InvitationGuest $guest, string $by, bool $souvenirs = false): array
    {
        $won = InvitationGuest::whereKey($guest->id)->where('checked_in', false)
            ->update(['checked_in' => true, 'checked_in_at' => now(), 'checked_in_by' => $by]);

        if ($won && $souvenirs && $invitation->track_souvenirs) {
            $this->giveSouvenirs($invitation, $guest, $by);
        }

        return ['result' => $won ? 'checked_in' : 'already_checked_in', 'guest' => $guest->fresh()];
    }

    /** @return array{result: 'given'|'already_given'|'off', guest: InvitationGuest} */
    public function giveSouvenirs(Invitation $invitation, InvitationGuest $guest, string $by): array
    {
        if (!$invitation->track_souvenirs) {
            return ['result' => 'off', 'guest' => $guest];
        }

        $won = InvitationGuest::whereKey($guest->id)->whereNull('souvenirs_at')
            ->update(['souvenirs_at' => now(), 'souvenirs_by' => $by]);

        return ['result' => $won ? 'given' : 'already_given', 'guest' => $guest->fresh()];
    }

    /** Guests by name, for ushers when a pass won't scan. */
    public function search(Invitation $invitation, string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }

        return $invitation->guests()
            ->where('full_name', 'ilike', '%' . addcslashes($q, '%_\\') . '%')
            ->orderBy('full_name')->limit(10)->get()
            ->map(fn ($g) => $this->card($g))->all();
    }

    /** Headline numbers, for the usher's counter and the host's dashboard. */
    public function counts(Invitation $invitation): array
    {
        $row = $invitation->guests()->toBase()->selectRaw(
            'SUM(CASE WHEN rsvp_status = ? THEN ' . self::PARTY_SQL . ' ELSE 0 END) AS expected,
             SUM(CASE WHEN checked_in THEN ' . self::PARTY_SQL . ' ELSE 0 END) AS arrived,
             SUM(CASE WHEN souvenirs_at IS NOT NULL THEN ' . self::PARTY_SQL . ' ELSE 0 END) AS souvenirs,
             SUM(CASE WHEN checked_in THEN 1 ELSE 0 END) AS passes_in,
             SUM(CASE WHEN rsvp_status = ? AND NOT checked_in THEN 1 ELSE 0 END) AS passes_waiting',
            ['attending', 'attending'],
        )->first();

        return [
            'expected' => (int) $row->expected,
            'arrived' => (int) $row->arrived,
            'souvenirs' => (int) $row->souvenirs,
            'passes_in' => (int) $row->passes_in,
            'passes_waiting' => (int) $row->passes_waiting,
        ];
    }

    /** Everything the host's live check-in page shows. */
    public function summary(Invitation $invitation): array
    {
        $guests = fn () => $invitation->guests();

        $tables = $guests()->toBase()
            ->whereNotNull('table_label')->where(fn ($q) => $q->where('rsvp_status', 'attending')->orWhere('checked_in', true))
            ->selectRaw('table_label, SUM(' . self::PARTY_SQL . ') AS seated, SUM(CASE WHEN checked_in THEN ' . self::PARTY_SQL . ' ELSE 0 END) AS arrived')
            ->groupBy('table_label')->get()
            ->map(fn ($t) => ['table' => $t->table_label, 'expected' => (int) $t->seated, 'arrived' => (int) $t->arrived])
            ->sortBy('table', SORT_NATURAL)->values()->all();

        $ushers = $guests()->toBase()->where('checked_in', true)->whereNotNull('checked_in_by')
            ->selectRaw('checked_in_by AS name, COUNT(*) AS scans')
            ->groupBy('checked_in_by')->orderByDesc('scans')->limit(20)->get()
            ->map(fn ($u) => ['name' => $u->name, 'scans' => (int) $u->scans])->all();

        return [
            'counts' => $this->counts($invitation),
            'arrivals' => $guests()->where('checked_in', true)->orderByDesc('checked_in_at')->limit(30)->get()
                ->map(fn ($g) => $this->card($g))->all(),
            'waiting' => $guests()->where('rsvp_status', 'attending')->where('checked_in', false)
                ->orderBy('full_name')->limit(60)->get()->map(fn ($g) => $this->card($g))->all(),
            'tables' => $tables,
            'ushers' => $ushers,
        ];
    }

    /** Switch the ushers' link on (making one the first time), or off. */
    public function setDoor(Invitation $invitation, bool $enabled, bool $newCode = false): Invitation
    {
        if ($enabled && (!$invitation->door_code || $newCode)) {
            $invitation->door_code = $this->freshCode();
        }
        $invitation->door_enabled = $enabled;
        $invitation->save();

        return $invitation;
    }

    private function freshCode(): string
    {
        do {
            // No 0/O or 1/I, so it can be read out over the phone.
            $code = collect(range(1, 8))->map(fn () => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'[random_int(0, 31)])->implode('');
        } while (Invitation::withTrashed()->where('door_code', $code)->exists());

        return $code;
    }

    public function doorUrl(Invitation $invitation): ?string
    {
        return $invitation->door_code ? rtrim((string) config('app.frontend_url'), '/') . '/door/' . $invitation->door_code : null;
    }

    public static function passUrl(InvitationGuest $guest): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/pass/' . $guest->qr_token;
    }

    /** "Bayo", trimmed and kept short; anything odd becomes "Usher". */
    public static function usherName(?string $raw): string
    {
        $name = trim(preg_replace('/\s+/', ' ', strip_tags((string) $raw)));

        return $name === '' ? 'Usher' : Str::limit($name, 40, '');
    }
}
