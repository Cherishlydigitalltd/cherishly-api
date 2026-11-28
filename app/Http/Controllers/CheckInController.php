<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Invitation;
use App\Models\InvitationGuest;
use App\Services\CheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * QR check-in. Three audiences:
 *  - guests: their pass (/pass/<qr_token>), public by its unguessable token;
 *  - ushers: the door link (/door/<door_code>), which the host can switch
 *    off or replace at any time;
 *  - the host: settings, seating and the live dashboard (signed in).
 */
class CheckInController extends Controller
{
    public function __construct(private CheckInService $checkIn) {}

    /* ── Guest's pass ─────────────────────────────── */

    /** GET /public/passes/{qrToken} */
    public function pass(string $qrToken): JsonResponse
    {
        $guest = strlen($qrToken) >= 20
            ? InvitationGuest::where('qr_token', $qrToken)->with('invitation')->first()
            : null;
        if (!$guest || !$guest->invitation) {
            return ApiResponse::notFound('We couldn’t find this pass. Check the link in your invitation.');
        }

        return ApiResponse::success('Pass.', [
            'guest' => [
                'full_name' => $guest->full_name,
                'rsvp_status' => $guest->rsvp_status,
                'table' => $guest->table_label,
                'party_size' => CheckInService::partySize($guest),
                'checked_in' => (bool) $guest->checked_in,
                'souvenirs_given' => $guest->souvenirs_at !== null,
            ],
            'event' => $this->checkIn->eventInfo($guest->invitation),
            'track_souvenirs' => (bool) $guest->invitation->track_souvenirs,
            'pass_url' => CheckInService::passUrl($guest),
            'code' => strtoupper(substr($guest->qr_token, 0, 6)),
        ]);
    }

    /* ── Ushers at the door ───────────────────────── */

    /** GET /public/door/{code} */
    public function door(string $code): JsonResponse
    {
        $invitation = $this->doorInvitation($code);

        return ApiResponse::success('Door.', [
            'event' => $this->checkIn->eventInfo($invitation),
            'track_souvenirs' => (bool) $invitation->track_souvenirs,
            'counts' => $this->checkIn->counts($invitation),
        ]);
    }

    /** POST /public/door/{code}/scan — look a pass up, change nothing yet. */
    public function scan(Request $request, string $code): JsonResponse
    {
        $invitation = $this->doorInvitation($code);
        $data = $request->validate(['scanned' => ['required', 'string', 'max:500']]);

        $guest = $this->checkIn->findGuest($invitation, $data['scanned']);
        if (!$guest) {
            return ApiResponse::error('This pass isn’t on this guest list.', ['scanned' => ['not_found']], 404);
        }

        return ApiResponse::success('Pass found.', ['guest' => $this->checkIn->card($guest)]);
    }

    /** POST /public/door/{code}/guests/{qrToken}/check-in */
    public function doorCheckIn(Request $request, string $code, string $qrToken): JsonResponse
    {
        $invitation = $this->doorInvitation($code);
        $guest = $this->doorGuest($invitation, $qrToken);
        $data = $request->validate(['souvenirs' => ['sometimes', 'boolean']]);

        $out = $this->checkIn->checkIn($invitation, $guest, $this->usher($request), (bool) ($data['souvenirs'] ?? false));

        return ApiResponse::success(
            $out['result'] === 'checked_in' ? 'Checked in.' : 'Already checked in.',
            ['result' => $out['result'], 'guest' => $this->checkIn->card($out['guest']), 'counts' => $this->checkIn->counts($invitation)],
        );
    }

    /** POST /public/door/{code}/guests/{qrToken}/souvenirs */
    public function doorSouvenirs(Request $request, string $code, string $qrToken): JsonResponse
    {
        $invitation = $this->doorInvitation($code);
        $guest = $this->doorGuest($invitation, $qrToken);

        $out = $this->checkIn->giveSouvenirs($invitation, $guest, $this->usher($request));

        return ApiResponse::success(
            ['given' => 'Souvenirs handed over.', 'already_given' => 'Souvenirs were already collected.', 'off' => 'Souvenirs aren’t tracked for this event.'][$out['result']],
            ['result' => $out['result'], 'guest' => $this->checkIn->card($out['guest'])],
        );
    }

    /** GET /public/door/{code}/search?q= */
    public function doorSearch(Request $request, string $code): JsonResponse
    {
        $invitation = $this->doorInvitation($code);
        $q = (string) $request->validate(['q' => ['required', 'string', 'max:60']])['q'];

        return ApiResponse::success('Guests.', ['guests' => $this->checkIn->search($invitation, $q)]);
    }

    /* ── The host ─────────────────────────────────── */

    /** GET /invitations/{invitation}/checkin */
    public function show(Request $request, Invitation $invitation): JsonResponse
    {
        $this->authorizeOwner($request, $invitation);

        return ApiResponse::success('Check-in.', $this->hostPayload($invitation));
    }

    /** PUT /invitations/{invitation}/checkin */
    public function update(Request $request, Invitation $invitation): JsonResponse
    {
        $this->authorizeOwner($request, $invitation);
        $data = $request->validate([
            'door_enabled' => ['sometimes', 'boolean'],
            'track_souvenirs' => ['sometimes', 'boolean'],
            'new_code' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('track_souvenirs', $data)) {
            $invitation->track_souvenirs = $data['track_souvenirs'];
            $invitation->save();
        }
        if (array_key_exists('door_enabled', $data) || !empty($data['new_code'])) {
            $this->checkIn->setDoor($invitation, (bool) ($data['door_enabled'] ?? $invitation->door_enabled), !empty($data['new_code']));
        }

        return ApiResponse::success('Check-in settings saved.', $this->hostPayload($invitation->fresh()));
    }

    /** GET /invitations/{invitation}/seating — attending guests, for table numbers. */
    public function seating(Request $request, Invitation $invitation): JsonResponse
    {
        $this->authorizeOwner($request, $invitation);

        $guests = $invitation->guests()->where('rsvp_status', 'attending')->orderBy('full_name')->limit(1000)->get()
            ->map(fn ($g) => ['id' => $g->id] + $this->checkIn->card($g));

        return ApiResponse::success('Seating.', ['guests' => $guests]);
    }

    /** PUT /invitations/{invitation}/guests/{guest}/seat */
    public function seat(Request $request, Invitation $invitation, InvitationGuest $guest): JsonResponse
    {
        $this->authorizeOwner($request, $invitation);
        abort_unless($guest->invitation_id === $invitation->id, 404);

        $data = $request->validate([
            'table' => ['nullable', 'string', 'max:20'],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        if (array_key_exists('table', $data)) {
            $guest->table_label = ($t = trim((string) $data['table'])) === '' ? null : $t;
        }
        if (array_key_exists('party_size', $data)) {
            $guest->party_size = $data['party_size'];
        }
        $guest->save();

        return ApiResponse::success('Saved.', ['guest' => ['id' => $guest->id] + $this->checkIn->card($guest)]);
    }

    /* ── Helpers ──────────────────────────────────── */

    private function hostPayload(Invitation $invitation): array
    {
        return [
            'door_enabled' => (bool) $invitation->door_enabled,
            'door_url' => $invitation->door_enabled ? $this->checkIn->doorUrl($invitation) : null,
            'door_code' => $invitation->door_enabled ? $invitation->door_code : null,
            'track_souvenirs' => (bool) $invitation->track_souvenirs,
            'event' => $this->checkIn->eventInfo($invitation),
        ] + $this->checkIn->summary($invitation);
    }

    private function doorInvitation(string $code): Invitation
    {
        $invitation = strlen($code) >= 6
            ? Invitation::where('door_code', strtoupper($code))->where('door_enabled', true)->first()
            : null;
        abort_unless($invitation, 404, 'This check-in link isn’t active. Ask the host for the current one.');

        return $invitation;
    }

    private function doorGuest(Invitation $invitation, string $qrToken): InvitationGuest
    {
        $guest = $invitation->guests()->where('qr_token', $qrToken)->first();
        abort_unless($guest, 404, 'This pass isn’t on this guest list.');

        return $guest;
    }

    private function usher(Request $request): string
    {
        return CheckInService::usherName($request->header('X-Usher-Name'));
    }

    private function authorizeOwner(Request $request, Invitation $invitation): void
    {
        abort_unless($invitation->user_id === $request->user()->id, 403, 'You do not have permission to access this invitation.');
    }
}
