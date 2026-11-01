<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Event;
use App\Models\GiftRegistry;
use App\Models\Invitation;
use App\Models\MemoryWall;
use App\Models\MonetaryGift;
use App\Models\SecretSanta;
use App\Services\StatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PublicController extends Controller
{
    public function __construct(
        private StatsService $statsService
    ) {
    }

    /**
     * GET /api/public/stats
     */
    public function stats(): JsonResponse
    {
        $stats = $this->statsService->getPlatformStats();
        return ApiResponse::success('Platform stats retrieved.', $stats);
    }

    /**
     * GET /api/public/preview/{type}/{token}
     *
     * The few details a shared link needs for its WhatsApp / social preview:
     * a title, one line of description and the cover photo. Nothing else,
     * so it is safe to serve to link-preview bots.
     */
    public function preview(string $type, string $token): JsonResponse
    {
        $data = Cache::remember("preview:$type:$token", 600, function () use ($type, $token) {
            return match ($type) {
                'e' => $this->fromModel(
                    Event::where('slug', $token)->where('is_active', true)->first(),
                    fn ($e) => $e->title,
                    'Everything about the day in one place: when, where, gifts and RSVP.',
                ),
                'registry' => $this->fromModel(
                    GiftRegistry::where('share_token', $token)->first(),
                    fn ($r) => $r->name,
                    'View the gift registry and choose a gift.',
                ),
                'give' => $this->fromModel(
                    MonetaryGift::where('share_token', $token)->where('is_active', true)->first(),
                    fn ($m) => $m->title,
                    'Send a cash gift in a few taps.',
                ),
                'rsvp' => $this->fromModel(
                    Invitation::where('share_token', $token)->first(),
                    fn ($i) => "You're invited: {$i->title}",
                    'Open your invitation and let the host know if you can make it.',
                ),
                'wall' => $this->fromModel(
                    MemoryWall::where('share_token', $token)->where('is_active', true)->first(),
                    fn ($w) => $w->title,
                    'Leave a message for the celebrant.',
                ),
                'santa' => $this->fromModel(
                    SecretSanta::where('share_token', $token)->first(),
                    fn ($s) => "Secret Santa: {$s->title}",
                    'Join the gift exchange and find out who you are buying for.',
                ),
                default => null,
            };
        });

        return $data
            ? ApiResponse::success('Preview retrieved.', $data)
            : ApiResponse::notFound('Not found.');
    }

    private function fromModel(?object $model, callable $title, string $fallback): ?array
    {
        if (!$model) {
            return null;
        }
        $about = trim(strip_tags((string) ($model->description ?? '')));

        return [
            'title' => Str::limit($title($model), 90),
            'description' => Str::limit($about !== '' ? preg_replace('/\s+/', ' ', $about) : $fallback, 160),
            'image' => $model->cover_photo ?: null,
        ];
    }
}
