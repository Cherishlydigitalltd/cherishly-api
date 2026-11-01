<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Event;
use App\Models\GiftRegistry;
use App\Models\Invitation;
use App\Models\MemoryWall;
use App\Models\MonetaryGift;
use App\Services\AssetService;
use App\Services\InvitationService;
use App\Services\MapLinkService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The event page (cherishlyng.com/e/<slug>): one link that brings together
 * the date, venue, schedule and dress code with the host's registry, cash
 * gift, wishes wall and RSVP.
 */
class EventPageController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private InvitationService $invitations,
        private MapLinkService $maps,
        private AssetService $assets,
    ) {
    }

    /* ─────────────────────────── Public ─────────────────────────── */

    /** GET /api/public/e/{slug} */
    public function show(string $slug): JsonResponse
    {
        $event = $this->findPublished($slug);
        if (!$event) {
            return ApiResponse::notFound('Event not found.');
        }

        return ApiResponse::success('Event page retrieved.', $this->present($event));
    }

    /**
     * POST /api/public/e/{slug}/rsvp
     * Only when the host has opened RSVP to anyone with the link. Invited-only
     * events are answered through each guest's own invitation link instead.
     */
    public function rsvp(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:30', 'required_without:email'],
            'status' => ['required', 'in:attending,declined'],
            'has_plus_one' => ['sometimes', 'boolean'],
        ]);

        $event = $this->findPublished($slug);
        $invitation = $event?->invitation;
        if (!$event || !$invitation) {
            return ApiResponse::notFound('Event not found.');
        }
        if ($event->rsvp_mode !== 'open') {
            return ApiResponse::error('Please use the RSVP link in your invitation.', statusCode: 403);
        }
        if (!$invitation->isRsvpOpen()) {
            return ApiResponse::error('RSVPs for this event have closed.', statusCode: 422);
        }

        // Someone already on the list answers through their own link, so a
        // stranger can't change another guest's reply by typing their email.
        $already = $invitation->guests()
            ->when(!empty($data['email']), fn ($q) => $q->where('email', $data['email']))
            ->when(empty($data['email']), fn ($q) => $q->where('phone', $data['phone']))
            ->exists();
        if ($already) {
            return ApiResponse::error(
                'You’re already on the guest list. Use the link in your invitation to change your answer.', statusCode: 409);
        }

        [$guest] = $this->invitations->addGuests($invitation, [[
            'full_name' => $data['full_name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'allow_plus_one' => true,
        ]]);
        $guest = $this->invitations->rsvp($guest, $data['status'], (bool) ($data['has_plus_one'] ?? false));

        return ApiResponse::success(
            $data['status'] === 'attending' ? 'See you there!' : 'Thanks for letting them know.',
            [
                'rsvp_status' => $guest->rsvp_status,
                // Their own pass, so a guest who gave only a phone number
                // still has something to show at the door.
                'pass_url' => $guest->rsvp_status === 'attending'
                    ? config('app.frontend_url') . '/rsvp/' . $invitation->share_token . '?guest=' . $guest->rsvp_token
                    : null,
            ]
        );
    }

    /* ─────────────────────────── Host ─────────────────────────── */

    /** GET /api/events/slug-check?slug=…&event=… */
    public function slugCheck(Request $request): JsonResponse
    {
        $slug = $this->slugify((string) $request->query('slug', ''));
        $ignore = (int) $request->query('event', 0);

        return ApiResponse::success('Checked.', [
            'slug' => $slug,
            'available' => $this->slugAvailable($slug, $ignore),
            'suggestion' => $this->suggestSlug($slug ?: 'my-event', $ignore),
        ]);
    }

    /** PUT /api/events/{event}/page */
    public function update(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);
        $user = $request->user();

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'event_type' => ['sometimes', Rule::in(Event::TYPES)],
            'headline' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:3000'],
            'slug' => ['sometimes', 'nullable', 'string', 'min:3', 'max:60'],
            // Each day or venue; the first one is the main date and place.
            'parts' => ['sometimes', 'array', 'min:1', 'max:6'],
            'parts.*.name' => ['nullable', 'string', 'max:80'],
            'parts.*.date' => ['nullable', 'date'],
            'parts.*.time' => ['nullable', 'date_format:H:i'],
            'parts.*.venue' => ['nullable', 'string', 'max:200'],
            'parts.*.address' => ['nullable', 'string', 'max:300'],
            'parts.*.map_link' => ['nullable', 'url', 'max:500'],
            'story' => ['nullable', 'string', 'max:5000'],
            'hashtag' => ['nullable', 'string', 'max:60', 'regex:/^#?[\pL\pN_]+$/u'],
            'livestream_url' => ['nullable', 'url', 'max:500'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'aso_ebi_url' => ['nullable', 'url', 'max:500'],
            'aso_ebi_note' => ['nullable', 'string', 'max:300'],
            'hidden_sections' => ['sometimes', 'array'],
            'hidden_sections.*' => [Rule::in(Event::SECTIONS)],
            // Remove or reorder photos already uploaded (new ones go
            // through the gallery upload).
            'gallery' => ['sometimes', 'array', 'max:' . Event::GALLERY_MAX],
            'gallery.*' => ['string', Rule::in($event->gallery ?? [])],
            'schedule' => ['nullable', 'array', 'max:20'],
            'schedule.*.time' => ['nullable', 'string', 'max:20'],
            'schedule.*.title' => ['required', 'string', 'max:120'],
            'schedule.*.note' => ['nullable', 'string', 'max:300'],
            'dress_code' => ['nullable', 'string', 'max:300'],
            'colours' => ['nullable', 'array', 'max:6'],
            'colours.*' => ['string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'rsvp_mode' => ['sometimes', Rule::in(['invited', 'open'])],
            'invitation_id' => [
                'nullable', 'integer',
                Rule::exists('invitations', 'id')->where('user_id', $user->id),
            ],
        ]);

        if (array_key_exists('slug', $data) && $data['slug'] !== null) {
            $data['slug'] = $this->slugify($data['slug']);
            if (!$this->slugAvailable($data['slug'], $event->id)) {
                return ApiResponse::error('That page name is taken. Try “'
                    . $this->suggestSlug($data['slug'], $event->id) . '”.', statusCode: 422);
            }
        }

        foreach (['livestream_url', 'aso_ebi_url'] as $key) {
            if (!empty($data[$key]) && !str_starts_with($data[$key], 'https://')) {
                return ApiResponse::error('Links must start with https://', statusCode: 422);
            }
        }
        if (!empty($data['hashtag'])) {
            $data['hashtag'] = '#' . ltrim($data['hashtag'], '#');
        }

        if (array_key_exists('parts', $data)) {
            $data['parts'] = $this->cleanParts($data['parts'], $event->parts ?? []);
            // The first part is the event's main date and place, which
            // invitations, reminders and the dashboard already use.
            $main = $data['parts'][0];
            $data += [
                'event_date' => $main['date'],
                'start_time' => $main['time'],
                'venue' => $main['venue'],
                'venue_address' => $main['address'],
                'map_link' => $main['map_link'],
                'map_lat' => $main['lat'],
                'map_lng' => $main['lng'],
            ];
        }

        $event->update($data);

        return ApiResponse::success('Event page saved.', $this->present($event->fresh(), forHost: true));
    }

    /**
     * PUT /api/events/{event}/page/links
     * Which of the host's registries, cash gifts and walls show on the page.
     */
    public function links(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);
        $user = $request->user();

        $data = $request->validate([
            'registry_ids' => ['present', 'array'],
            'registry_ids.*' => ['integer'],
            'monetary_ids' => ['present', 'array'],
            'monetary_ids.*' => ['integer'],
            'wall_ids' => ['present', 'array'],
            'wall_ids.*' => ['integer'],
        ]);

        foreach ([
            GiftRegistry::class => 'registry_ids',
            MonetaryGift::class => 'monetary_ids',
            MemoryWall::class => 'wall_ids',
        ] as $model => $key) {
            // Only the host's own items, so nobody can pull someone else's
            // registry onto their page.
            $model::where('event_id', $event->id)->whereNotIn('id', $data[$key])->update(['event_id' => null]);
            $model::where('user_id', $user->id)->whereIn('id', $data[$key])->update(['event_id' => $event->id]);
        }

        return ApiResponse::success('Event page updated.', $this->present($event->fresh(), forHost: true));
    }

    /** POST /api/events/{event}/page/cover  (multipart: photo) */
    public function cover(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);
        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240']]);

        $event->update([
            'cover_photo' => $this->assets->replace($event->cover_photo, $request->file('photo'), 'events'),
        ]);

        return ApiResponse::success('Cover photo updated.', $this->present($event->fresh(), forHost: true));
    }

    /** POST /api/events/{event}/page/gallery  (multipart: photo) */
    public function addPhoto(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);
        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240']]);

        $gallery = $event->gallery ?? [];
        if (count($gallery) >= Event::GALLERY_MAX) {
            return ApiResponse::error('You can add up to ' . Event::GALLERY_MAX . ' photos.', statusCode: 422);
        }

        $gallery[] = $this->assets->upload($request->file('photo'), 'events/gallery');
        $event->update(['gallery' => $gallery]);

        return ApiResponse::success('Photo added.', $this->present($event->fresh(), forHost: true));
    }

    /** GET /api/events/{event}/page */
    public function edit(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);
        return ApiResponse::success('Event page retrieved.', $this->present($event, forHost: true));
    }

    /* ─────────────────────────── Helpers ─────────────────────────── */

    /**
     * Tidy the days/venues and work out each map pin. A link that hasn't
     * changed keeps its pin, so saving doesn't re-fetch every short link.
     */
    private function cleanParts(array $parts, array $previous): array
    {
        $known = collect($previous)->filter(fn ($p) => !empty($p['map_link']))
            ->keyBy('map_link');

        return collect($parts)->map(function ($p) use ($known) {
            $link = trim((string) ($p['map_link'] ?? '')) ?: null;
            if ($link && !$this->maps->isGoogleMapsLink($link)) {
                $link = null; // only Google Maps links become pins
            }
            $pin = $link
                ? ($known->has($link) && isset($known[$link]['lat'])
                    ? [$known[$link]['lat'], $known[$link]['lng']]
                    : $this->maps->coordinates($link))
                : null;

            return [
                'name' => trim((string) ($p['name'] ?? '')) ?: null,
                'date' => $p['date'] ?? null,
                'time' => $p['time'] ?? null,
                'venue' => trim((string) ($p['venue'] ?? '')) ?: null,
                'address' => trim((string) ($p['address'] ?? '')) ?: null,
                'map_link' => $link,
                'lat' => $pin[0] ?? null,
                'lng' => $pin[1] ?? null,
            ];
        })->values()->all();
    }

    /** Each day/venue as the page shows it, with its map. */
    private function venues(Event $event): array
    {
        $parts = $event->parts ?: [[
            'name' => null,
            'date' => $event->event_date?->toDateString(),
            'time' => $event->start_time ? substr($event->start_time, 0, 5) : null,
            'venue' => $event->venue,
            'address' => $event->venue_address,
            'map_link' => $event->map_link,
            'lat' => $event->map_lat,
            'lng' => $event->map_lng,
        ]];

        return collect($parts)
            ->filter(fn ($p) => $p['date'] || $p['venue'] || $p['address'])
            ->map(function ($p) {
                $hasPin = isset($p['lat'], $p['lng']);
                $query = $hasPin ? "{$p['lat']},{$p['lng']}" : ($p['address'] ?: $p['venue']);
                return $p + [
                    'map_embed' => $query
                        ? 'https://www.google.com/maps?output=embed&z=15&q=' . rawurlencode($query)
                        : null,
                    'directions_url' => $p['map_link'] ?: ($query
                        ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query)
                        : null),
                ];
            })->values()->all();
    }


    /** 0803 123 4567 → 2348031234567 */
    private function intlPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($digits, '0')) {
            $digits = '234' . substr($digits, 1);
        }
        return $digits;
    }

    private function findPublished(string $slug): ?Event
    {
        return Event::where('slug', Str::slug($slug))->where('is_active', true)->first();
    }

    /** "Tolu & Ada" → "tolu-and-ada". */
    private function slugify(string $text): string
    {
        return Str::slug(str_replace('&', ' and ', $text));
    }

    private function slugAvailable(string $slug, int $ignoreId = 0): bool
    {
        if (strlen($slug) < 3 || in_array($slug, Event::RESERVED_SLUGS, true)) {
            return false;
        }
        return !Event::withTrashed()->where('slug', $slug)->where('id', '!=', $ignoreId)->exists();
    }

    private function suggestSlug(string $base, int $ignoreId = 0): string
    {
        $base = Str::limit($this->slugify($base), 50, '') ?: 'my-event';
        if ($this->slugAvailable($base, $ignoreId)) {
            return $base;
        }
        for ($i = 2; $i < 50; $i++) {
            if ($this->slugAvailable("$base-$i", $ignoreId)) {
                return "$base-$i";
            }
        }
        return $base . '-' . Str::lower(Str::random(4));
    }

    private function present(Event $event, bool $forHost = false): array
    {
        $event->loadMissing(['user', 'invitation']);
        $mapQuery = $event->venue_address ?: $event->venue;
        $inv = $event->invitation;

        $registries = $event->registries()->with('gifts')->get();
        $cash = $event->monetaryGifts()->when(!$forHost, fn ($q) => $q->where('is_active', true))->get();
        $walls = $event->walls()->when(!$forHost, fn ($q) => $q->where('is_active', true))->get();

        $page = [
            'id' => $event->id,
            'slug' => $event->slug,
            'page_url' => $event->page_url,
            'title' => $event->title,
            'event_type' => $event->event_type ?? 'other',
            'headline' => $event->headline,
            'description' => $event->description,
            'story' => $event->story,
            'gallery' => $event->gallery ?? [],
            'venues' => $this->venues($event),
            'hashtag' => $event->hashtag,
            'livestream_url' => $event->livestream_url,
            'contact' => $event->contact_phone ? [
                'name' => $event->contact_name,
                'phone' => $event->contact_phone,
                // wa.me needs the number in international form, digits only.
                'whatsapp' => 'https://wa.me/' . $this->intlPhone($event->contact_phone),
            ] : null,
            'aso_ebi' => $event->aso_ebi_url || $event->aso_ebi_note ? [
                'url' => $event->aso_ebi_url,
                'note' => $event->aso_ebi_note,
            ] : null,
            'hidden_sections' => $event->hidden_sections ?? [],
            'cover_photo' => $event->cover_photo,
            'event_date' => $event->event_date?->toDateString(),
            'start_time' => $event->start_time ? substr($event->start_time, 0, 5) : null,
            'venue' => $event->venue,
            'venue_address' => $event->venue_address,
            'map_url' => $mapQuery
                ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($mapQuery)
                : null,
            'schedule' => $event->schedule ?? [],
            'dress_code' => $event->dress_code,
            'colours' => $event->colours ?? [],
            'accent_color' => $event->accent_color,
            'host' => optional($event->user)->full_name,
            'rsvp' => $inv ? [
                'mode' => $event->rsvp_mode,
                'is_open' => $inv->isRsvpOpen(),
                'deadline' => $inv->rsvp_deadline?->toDateString(),
            ] : null,
            'registries' => $registries->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'cover_photo' => $r->cover_photo,
                'url' => $r->public_url,
                'total_gifts' => $r->total_gifts,
                'total_contributed' => (float) $r->total_contributed,
                'target' => (float) $r->gifts->sum('price'),
            ])->values(),
            'cash_gifts' => $cash->map(fn ($m) => [
                'id' => $m->id,
                'title' => $m->title,
                'cover_photo' => $m->cover_photo,
                'url' => $m->public_url,
                'amount_raised' => (float) $m->amount_raised,
                'target_amount' => (float) $m->target_amount,
                'funded_percentage' => $m->funded_percentage,
            ])->values(),
            'walls' => $walls->map(fn ($w) => [
                'id' => $w->id,
                'title' => $w->title,
                'wall_type' => $w->wall_type,
                'url' => $w->public_url,
                'wishes_count' => $w->wishes_count,
            ])->values(),
        ];

        if ($forHost) {
            $page['rsvp_mode'] = $event->rsvp_mode;
            $page['invitation_id'] = $event->invitation_id;
            $page['invitation'] = $inv ? ['id' => $inv->id, 'title' => $inv->title] : null;
        }

        return $page;
    }
}
