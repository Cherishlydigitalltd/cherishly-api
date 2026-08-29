<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Invitation\AddGuestRequest;
use App\Http\Requests\Invitation\CreateInvitationRequest;
use App\Http\Requests\Invitation\RsvpRequest;
use App\Models\Invitation;
use App\Models\InvitationGuest;
use App\Services\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvitationController extends Controller
{
    public function __construct(
        private InvitationService $invitationService
    ) {}

    /* ── Protected endpoints ── */

    /**
     * GET /api/invitations
     */
    public function index(Request $request): JsonResponse
    {
        $invitations = $this->invitationService->getUserInvitations($request->user());
        return ApiResponse::success('Invitations retrieved.', $invitations);
    }

    /**
     * POST /api/invitations
     */
    public function store(CreateInvitationRequest $request): JsonResponse
    {
        $invitation = $this->invitationService->create($request->user(), $request->validated());
        return ApiResponse::success('Invitation created successfully.', $invitation, 201);
    }

    /**
     * GET /api/invitations/{id}
     */
    public function show(Request $request, Invitation $invitation): JsonResponse
    {
        $this->authorizeOwner($request, $invitation);
        $invitation->load('guests');
        return ApiResponse::success('Invitation retrieved.', $invitation);
    }

    /**
     * PUT /api/invitations/{id}
     */
    public function update(CreateInvitationRequest $request, Invitation $invitation): JsonResponse
    {
        $this->authorizeOwner($request, $invitation);
        $invitation = $this->invitationService->update($invitation, $request->validated());
        return ApiResponse::success('Invitation updated successfully.', $invitation);
    }

    /**
     * DELETE /api/invitations/{id}
     */
    public function destroy(Request $request, Invitation $invitation): JsonResponse
    {
        $this->authorizeOwner($request, $invitation);
        $this->invitationService->delete($invitation);
        return ApiResponse::success('Invitation deleted successfully.');
    }

    /**
     * POST /api/invitations/{id}/guests
     * Add guests manually
     */
    public function addGuests(AddGuestRequest $request, Invitation $invitation): JsonResponse
    {
        $this->authorizeOwner($request, $invitation);
        $guests = $this->invitationService->addGuests($invitation, $request->guests);
        return ApiResponse::success('Guests added successfully.', $guests, 201);
    }

    /**
     * POST /api/invitations/{id}/guests/import
     * Upload Excel/CSV
     */
    public function importGuests(Request $request, Invitation $invitation): JsonResponse
    {
        $this->authorizeOwner($request, $invitation);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,xlsx,xls', 'max:10240'],
        ]);

        $guests = $this->invitationService->importGuests($invitation, $request->file('file'));
        return ApiResponse::success(count($guests) . ' guests imported successfully.', ['count' => count($guests)], 201);
    }

    /**
     * GET /api/invitations/{id}/guests
     */
    public function guests(Request $request, Invitation $invitation): JsonResponse
    {
        $this->authorizeOwner($request, $invitation);
        $filters = $request->only(['search', 'rsvp_status']);
        $guests = $this->invitationService->getGuests($invitation, $filters);
        return ApiResponse::success('Guests retrieved.', $guests);
    }

    /**
     * DELETE /api/invitations/{id}/guests/{guestId}
     */
    public function removeGuest(Request $request, Invitation $invitation, InvitationGuest $guest): JsonResponse
    {
        $this->authorizeOwner($request, $invitation);
        $this->authorizeGuest($guest, $invitation);
        $this->invitationService->removeGuest($guest);
        return ApiResponse::success('Guest removed successfully.');
    }

    /**
     * POST /api/invitations/{id}/send
     * Send invitations to all guests with email
     */
    public function send(Request $request, Invitation $invitation): JsonResponse
    {
        $this->authorizeOwner($request, $invitation);
        $count = $this->invitationService->sendInvitations($invitation);
        return ApiResponse::success("Invitations sent to {$count} guests.");
    }

    /**
     * POST /api/invitations/{id}/guests/{guestId}/checkin
     * Check in a guest
     */
    public function checkIn(Request $request, Invitation $invitation, InvitationGuest $guest): JsonResponse
    {
        $this->authorizeOwner($request, $invitation);
        $this->authorizeGuest($guest, $invitation);
        $guest = $this->invitationService->checkIn($guest);
        return ApiResponse::success('Guest checked in successfully.', $guest);
    }

    /**
     * GET /api/invitations/{id}/attendance
     * Real-time attendance stats
     */
    public function attendance(Request $request, Invitation $invitation): JsonResponse
    {
        $this->authorizeOwner($request, $invitation);
        $data = $this->invitationService->getAttendance($invitation);
        return ApiResponse::success('Attendance retrieved.', $data);
    }

    /* ── Public endpoints ── */

    /**
     * GET /api/public/invitations/{token}
     */
    public function publicShow(string $token): JsonResponse
    {
        $invitation = $this->invitationService->findByToken($token);

        if (!$invitation) {
            return ApiResponse::notFound('Invitation not found.');
        }

        return ApiResponse::success('Invitation retrieved.', [
            'id'           => $invitation->id,
            'title'        => $invitation->title,
            'description'  => $invitation->description,
            'cover_photo'  => $invitation->cover_photo,
            'rsvp_deadline'=> $invitation->rsvp_deadline,
            'is_rsvp_open' => $invitation->isRsvpOpen(),
            'host'         => $invitation->user->full_name,
        ]);
    }

    /**
     * GET /api/public/invitations/guest/{qrToken}
     * Get guest details by QR token
     */
    public function guestByQr(string $qrToken): JsonResponse
    {
        $guest = $this->invitationService->findGuestByQrToken($qrToken);

        if (!$guest) {
            return ApiResponse::notFound('Guest not found.');
        }

        return ApiResponse::success('Guest retrieved.', [
            'id'          => $guest->id,
            'full_name'   => $guest->full_name,
            'rsvp_status' => $guest->rsvp_status,
            'checked_in'  => $guest->checked_in,
            'event'       => $guest->invitation->title,
        ]);
    }

    /**
     * POST /api/public/invitations/{token}/rsvp/{guestId}
     */
    public function rsvp(RsvpRequest $request, string $token, InvitationGuest $guest): JsonResponse
    {
        $invitation = $this->invitationService->findByToken($token);

        if (!$invitation) {
            return ApiResponse::notFound('Invitation not found.');
        }

        if (!$invitation->isRsvpOpen()) {
            return ApiResponse::error('RSVP deadline has passed.');
        }

        if ($guest->invitation_id !== $invitation->id) {
            return ApiResponse::error('Invalid guest for this invitation.');
        }

        $guest = $this->invitationService->rsvp(
            $guest,
            $request->rsvp_status,
            $request->has_plus_one ?? false
        );

        return ApiResponse::success('RSVP confirmed successfully.', [
            'guest'       => $guest->full_name,
            'rsvp_status' => $guest->rsvp_status,
            'qr_token'    => $guest->qr_token,
            'event'       => $invitation->title,
        ]);
    }

    /* ── Helpers ── */

    private function authorizeOwner(Request $request, Invitation $invitation): void
    {
        if ($invitation->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to access this invitation.');
        }
    }

    private function authorizeGuest(InvitationGuest $guest, Invitation $invitation): void
    {
        if ($guest->invitation_id !== $invitation->id) {
            abort(404, 'Guest not found in this invitation.');
        }
    }
}
