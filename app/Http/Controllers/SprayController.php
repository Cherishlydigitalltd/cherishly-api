<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Event;
use App\Models\SprayGuest;
use App\Services\SprayService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SprayController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private SprayService $spray) {}

    /* ── Public: guests and the big screen (/api/public/spray/{code}) ── */

    public function show(string $code): JsonResponse
    {
        return ApiResponse::success('Spray page.', $this->spray->publicInfo($this->event($code)));
    }

    public function join(Request $request, string $code): JsonResponse
    {
        $event = $this->event($code);
        $data = $request->validate([
            'is_anonymous' => ['sometimes', 'boolean'],
            'display_name' => ['required_unless:is_anonymous,true', 'nullable', 'string', 'max:40'],
            'message' => ['nullable', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:191'],
        ]);

        $guest = $this->spray->join($event, $data);

        return ApiResponse::success('Welcome! Load your bundle to start spraying.', [
            'token' => $guest->token,
            'guest' => $this->spray->guestState($guest),
        ], 201);
    }

    public function me(Request $request, string $code): JsonResponse
    {
        $event = $this->event($code);
        $guest = $this->guest($request, $event);

        return ApiResponse::success('Your bundle.', $this->spray->guestState($guest));
    }

    public function updateMe(Request $request, string $code): JsonResponse
    {
        $event = $this->event($code);
        $guest = $this->guest($request, $event);
        $data = $request->validate([
            'is_anonymous' => ['sometimes', 'boolean'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:40'],
            'message' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);
        if (($data['is_anonymous'] ?? $guest->is_anonymous) === false && array_key_exists('display_name', $data) && !trim((string) $data['display_name'])) {
            return ApiResponse::validationError(['display_name' => ['Add your name, or spray anonymously.']]);
        }
        $guest->update($data);

        return ApiResponse::success('Saved.', $this->spray->guestState($guest));
    }

    public function topup(Request $request, string $code): JsonResponse
    {
        $event = $this->event($code);
        $guest = $this->guest($request, $event);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:' . SprayService::MIN_TOPUP, 'max:' . SprayService::MAX_TOPUP],
            'bvn' => ['nullable', 'digits:11'],
            'bvn_first_name' => ['required_with:bvn', 'nullable', 'string', 'max:60'],
            'bvn_last_name' => ['required_with:bvn', 'nullable', 'string', 'max:60'],
        ], [
            'amount.min' => 'The smallest bundle is ₦' . number_format(SprayService::MIN_TOPUP) . '.',
        ]);

        try {
            $result = $this->spray->startTopup($event, $guest, $data);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), null, 502);
        }

        return ApiResponse::success('Complete your payment to load your bundle.', $result, 201);
    }

    public function sprayNotes(Request $request, string $code): JsonResponse
    {
        $event = $this->event($code);
        $guest = $this->guest($request, $event);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:200', 'max:2000000'],
            'notes' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);
        if (fmod((float) $data['amount'], 100) != 0) {
            return ApiResponse::validationError(['amount' => ['Spray whole notes only.']]);
        }

        return ApiResponse::success('Sprayed!', $this->spray->spray($event, $guest, (float) $data['amount'], (int) $data['notes']));
    }

    public function screen(Request $request, string $code): JsonResponse
    {
        $after = $request->query('after');

        return ApiResponse::success('Screen.', $this->spray->screen($this->event($code), is_numeric($after) ? (int) $after : null));
    }

    /* ── Host (/api/events/{event}/spray) ── */

    public function hostShow(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        return ApiResponse::success('Spray.', $this->spray->hostSummary($event));
    }

    public function hostUpdate(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);
        $data = $request->validate([
            'spray_enabled' => ['sometimes', 'boolean'],
            'spray_show_total' => ['sometimes', 'boolean'],
            'spray_show_leaders' => ['sometimes', 'boolean'],
        ]);

        $this->spray->updateSettings($event, $data);

        return ApiResponse::success('Spray settings saved.', $this->spray->hostSummary($event->fresh()));
    }

    public function hostEnd(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);
        $this->spray->end($event);

        return ApiResponse::success('Spraying has ended. Thank you!', $this->spray->hostSummary($event->fresh()));
    }

    public function hostHideMessage(Request $request, Event $event, SprayGuest $guest): JsonResponse
    {
        $this->authorize('update', $event);
        abort_unless($guest->event_id === $event->id, 404);
        $data = $request->validate(['hidden' => ['required', 'boolean']]);
        $guest->update(['message_hidden' => $data['hidden']]);

        return ApiResponse::success($data['hidden'] ? 'Message hidden from the screen.' : 'Message shown again.', $this->spray->hostSummary($event));
    }

    /* ── Helpers ── */

    private function event(string $code): Event
    {
        return Event::where('spray_code', strtoupper($code))->with('user')->firstOrFail();
    }

    private function guest(Request $request, Event $event): SprayGuest
    {
        $token = (string) $request->header('X-Spray-Token', '');
        $guest = $token !== '' ? SprayGuest::where('token', $token)->where('event_id', $event->id)->first() : null;
        abort_unless($guest, 403, 'Please start again from the spray link.');

        return $guest;
    }
}
