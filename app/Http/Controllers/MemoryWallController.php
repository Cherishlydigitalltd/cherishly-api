<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Wall\CreateWallRequest;
use App\Http\Requests\Wall\CreateWishRequest;
use App\Models\MemoryWall;
use App\Models\Wish;
use App\Services\MemoryWallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemoryWallController extends Controller
{
    public function __construct(
        private MemoryWallService $wallService
    ) {
    }

    /**
     * GET /api/walls
     */
    public function index(Request $request): JsonResponse
    {
        $walls = $this->wallService->getUserWalls($request->user());
        return ApiResponse::success('Memory walls retrieved.', $walls);
    }

    /**
     * POST /api/walls
     */
    public function store(CreateWallRequest $request): JsonResponse
    {
        $wall = $this->wallService->create($request->user(), $request->validated());
        return ApiResponse::success('Memory wall created successfully.', $wall, 201);
    }

    /**
     * GET /api/walls/{id}
     */
    public function show(Request $request, MemoryWall $wall): JsonResponse
    {
        $this->authorizeOwner($request, $wall);
        $wall->loadCount('wishes');
        return ApiResponse::success('Memory wall retrieved.', $wall);
    }

    /**
     * PUT /api/walls/{id}
     */
    public function update(CreateWallRequest $request, MemoryWall $wall): JsonResponse
    {
        $this->authorizeOwner($request, $wall);
        $wall = $this->wallService->update($wall, $request->validated());
        return ApiResponse::success('Memory wall updated successfully.', $wall);
    }

    /**
     * DELETE /api/walls/{id}
     */
    public function destroy(Request $request, MemoryWall $wall): JsonResponse
    {
        $this->authorizeOwner($request, $wall);
        $this->wallService->delete($wall);
        return ApiResponse::success('Memory wall deleted successfully.');
    }

    /**
     * GET /api/walls/{id}/wishes
     */
    public function wishes(Request $request, MemoryWall $wall): JsonResponse
    {
        $this->authorizeOwner($request, $wall);
        $wishes = $this->wallService->getWishes($wall);
        return ApiResponse::success('Wishes retrieved.', $wishes);
    }

    /**
     * DELETE /api/walls/{id}/wishes/{wishId}
     * Owner can delete inappropriate wishes
     */
    public function deleteWish(Request $request, MemoryWall $wall, Wish $wish): JsonResponse
    {
        $this->authorizeOwner($request, $wall);
        $this->authorizeWish($wish, $wall);
        $this->wallService->deleteWish($wish);
        return ApiResponse::success('Wish deleted successfully.');
    }

    /* ── Public endpoints ── */

    /**
     * GET /api/public/walls/{token}
     */
    public function publicShow(string $token): JsonResponse
    {
        $wall = $this->wallService->findByToken($token);

        if (!$wall) {
            return ApiResponse::notFound('Memory wall not found or is no longer active.');
        }

        return ApiResponse::success('Memory wall retrieved.', [
            'id' => $wall->id,
            'title' => $wall->title,
            'description' => $wall->description,
            'cover_photo' => $wall->cover_photo,
            'wall_type' => $wall->wall_type ?? 'wishes',
            'host' => $wall->user->full_name,
            'public_url' => $wall->public_url,
            'share_token' => $wall->share_token,
        ]);
    }

    /**
     * GET /api/public/walls/{token}/wishes
     */
    public function publicWishes(string $token): JsonResponse
    {
        $wall = $this->wallService->findByToken($token);

        if (!$wall) {
            return ApiResponse::notFound('Memory wall not found.');
        }

        $wishes = $this->wallService->getPublicWishes($wall);
        return ApiResponse::success('Wishes retrieved.', $wishes);
    }

    /**
     * POST /api/public/walls/{token}/wishes
     */
    public function addWish(CreateWishRequest $request, string $token): JsonResponse
    {
        $wall = $this->wallService->findByToken($token);

        if (!$wall) {
            return ApiResponse::notFound('Memory wall not found or is no longer active.');
        }

        $wish = $this->wallService->addWish($wall, $request->validated());

        return ApiResponse::success('Wish shared successfully.', $wish, 201);
    }

    /* ── Helpers ── */

    private function authorizeOwner(Request $request, MemoryWall $wall): void
    {
        if ($wall->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to access this memory wall.');
        }
    }

    private function authorizeWish(Wish $wish, MemoryWall $wall): void
    {
        if ($wish->wall_id !== $wall->id) {
            abort(404, 'Wish not found on this wall.');
        }
    }
}
