<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Event;
use App\Models\EventGuest;
use App\Services\EventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class EventController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private EventService $eventService)
    {
    }

    /* ── List events ── */
    public function index(Request $request): JsonResponse
    {
        $events = $this->eventService->list($request->user());
        return ApiResponse::success('Events retrieved.', $events);
    }

    /* ── Create event ── */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'cover_photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'event_date' => ['nullable', 'date'],
            'venue' => ['nullable', 'string', 'max:300'],
        ]);

        $event = $this->eventService->create($request->user(), $request->all());
        return ApiResponse::success('Event created.', $event, 201);
    }

    /* ── Get event ── */
    public function show(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);
        $event->loadCount('guests');
        return ApiResponse::success('Event retrieved.', $event->append(['public_url']));
    }

    /* ── Update event ── */
    public function update(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);
        $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'event_date' => ['nullable', 'date'],
            'venue' => ['nullable', 'string', 'max:300'],
        ]);
        $event->update($request->only(['title', 'description', 'event_date', 'venue']));
        return ApiResponse::success('Event updated.', $event->fresh());
    }

    /* ── Delete event ── */
    public function destroy(Request $request, Event $event): JsonResponse
    {
        $this->authorize('delete', $event);
        $event->delete();
        return ApiResponse::success('Event deleted.');
    }

    /* ── Get guests ── */
    public function guests(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);
        $guests = $this->eventService->getGuests($event, $request->all());
        return ApiResponse::success('Guests retrieved.', $guests);
    }

    /* ── Add guests ── */
    public function addGuests(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);
        $request->validate([
            'guests' => ['required', 'array', 'min:1'],
            'guests.*.full_name' => ['required', 'string'],
            'guests.*.email' => ['nullable', 'email'],
            'guests.*.phone' => ['nullable', 'string'],
            'guests.*.allow_plus_one' => ['nullable', 'boolean'],
        ]);
        $guests = $this->eventService->addGuests($event, $request->guests);
        return ApiResponse::success('Guests added.', $guests, 201);
    }

    /* ── Import guests from Excel ── */
    public function importGuests(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,xlsx,xls'],
        ]);
        $guests = $this->eventService->importGuests($event, $request->file('file'));
        return ApiResponse::success(count($guests) . ' guests imported.', $guests, 201);
    }

    /* ── Remove guest ── */
    public function removeGuest(Request $request, Event $event, EventGuest $guest): JsonResponse
    {
        $this->authorize('update', $event);
        $guest->delete();
        return ApiResponse::success('Guest removed.');
    }

    /* ── Check in guest ── */
    public function checkIn(Request $request, Event $event, EventGuest $guest): JsonResponse
    {
        $this->authorize('update', $event);
        $guest = $this->eventService->checkIn($guest);
        return ApiResponse::success('Guest checked in.', $guest);
    }

    /* ── Attendance stats ── */
    public function attendance(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);
        $stats = $this->eventService->attendance($event);
        return ApiResponse::success('Attendance retrieved.', $stats);
    }

    /* ── Public show ── */


    public function sendInvitations(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $guests = $event->guests()->whereNotNull('email')->get();

        foreach ($guests as $guest) {
            \App\Jobs\SendEventInvitationEmail::dispatch($guest, $event);
        }

        return ApiResponse::success("Invitations sent to {$guests->count()} guests.");
    }

    /**
     * GET /api/public/events/{token}
     */
    public function publicShow(string $token): JsonResponse
    {
        $event = $this->eventService->findByToken($token);
        if (!$event)
            return ApiResponse::notFound('Event not found.');

        return ApiResponse::success('Event retrieved.', [
            'id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,
            'cover_photo' => $event->cover_photo,
            'event_date' => $event->event_date,
            'venue' => $event->venue,
            'host' => optional($event->user)->full_name,
            'share_token' => $event->share_token,
        ]);
    }

    /**
     * GET /api/public/events/{token}/guest/{guestId}
     */
    public function publicGuest(string $token, int $guestId): JsonResponse
    {
        $event = $this->eventService->findByToken($token);
        if (!$event)
            return ApiResponse::notFound('Event not found.');

        $guest = $event->guests()->find($guestId);
        if (!$guest)
            return ApiResponse::notFound('Guest not found.');

        return ApiResponse::success('Guest retrieved.', [
            'id' => $guest->id,
            'full_name' => $guest->full_name,
            'email' => $guest->email,
            'qr_token' => $guest->qr_token,
            'qr_url' => $guest->qr_url,
            'rsvp_status' => $guest->rsvp_status,
        ]);
    }

    /**
     * POST /api/public/events/{token}/rsvp
     */
    public function publicRsvp(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'guest_id' => ['nullable', 'integer'],
            'status' => ['required', 'in:attending,declined'],
        ]);

        $event = $this->eventService->findByToken($token);
        if (!$event)
            return ApiResponse::notFound('Event not found.');

        $guest = $event->guests()->find($request->guest_id);
        if (!$guest)
            return ApiResponse::notFound('Guest not found.');

        $guest->update(['rsvp_status' => $request->status]);

        // Send confirmation email if attending
        if ($request->status === 'attending' && $guest->email) {
            \App\Jobs\SendEventConfirmationEmail::dispatch($guest->fresh(), $event);
        }

        return ApiResponse::success('RSVP submitted.', [
            'rsvp_status' => $guest->fresh()->rsvp_status,
            'qr_token' => $guest->qr_token,
            'qr_url' => $guest->qr_url,
        ]);
    }

    /**
     * GET /api/public/events/checkin/{qrToken}
     */
    public function checkInByQr(string $qrToken): JsonResponse
    {
        $guest = EventGuest::where('qr_token', $qrToken)->with('event')->first();
        if (!$guest)
            return ApiResponse::notFound('Guest not found.');

        $guest = $this->eventService->checkIn($guest);

        return ApiResponse::success('Guest checked in.', [
            'full_name' => $guest->full_name,
            'event' => $guest->event->title,
            'checked_in_at' => $guest->checked_in_at,
        ]);
    }

}
