<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Registry\ContributeRequest;
use App\Http\Requests\Registry\CreateGiftRequest;
use App\Http\Requests\Registry\CreateRegistryRequest;
use App\Http\Requests\Registry\UpdateRegistryRequest;
use App\Models\Gift;
use App\Models\GiftRegistry;
use App\Services\GiftRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\CatalogGift;

class GiftRegistryController extends Controller
{
    public function __construct(
        private GiftRegistryService $registryService
    ) {
    }

    /* ────────────────────────────────────────────
     | REGISTRY ENDPOINTS
     ──────────────────────────────────────────── */

    /**
     * GET /api/registries
     * List all registries for authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        $registries = $this->registryService->getUserRegistries($request->user());

        return ApiResponse::success('Registries retrieved.', $registries);
    }

    /**
     * POST /api/registries
     * Create a new registry
     */
    public function store(CreateRegistryRequest $request): JsonResponse
    {
        $registry = $this->registryService->create($request->user(), $request->validated());

        return ApiResponse::success('Gift registry created successfully.', $registry, 201);
    }

    /**
     * GET /api/registries/{id}
     * Get a single registry with gifts
     */
    public function show(Request $request, GiftRegistry $registry): JsonResponse
    {
        $this->authorizeOwner($request, $registry);

        $registry->load([
            'gifts' => function ($q) {
                $q->withCount('successfulContributions');
            }
        ]);

        return ApiResponse::success('Registry retrieved.', $registry);
    }

    /**
     * PUT /api/registries/{id}
     * Update a registry
     */
    public function update(UpdateRegistryRequest $request, GiftRegistry $registry): JsonResponse
    {
        $this->authorizeOwner($request, $registry);

        $registry = $this->registryService->update($registry, $request->validated());

        return ApiResponse::success('Registry updated successfully.', $registry);
    }

    /**
     * DELETE /api/registries/{id}
     * Delete a registry
     */
    public function destroy(Request $request, GiftRegistry $registry): JsonResponse
    {
        $this->authorizeOwner($request, $registry);

        $this->registryService->delete($registry);

        return ApiResponse::success('Registry deleted successfully.');
    }

    /* ────────────────────────────────────────────
     | GIFT ENDPOINTS
     ──────────────────────────────────────────── */

    /**
     * GET /api/registries/{id}/gifts
     * List all gifts in a registry
     */
    public function gifts(Request $request, GiftRegistry $registry): JsonResponse
    {
        $this->authorizeOwner($request, $registry);

        $gifts = $this->registryService->getGifts($registry);

        return ApiResponse::success('Gifts retrieved.', $gifts);
    }

    /**
     * POST /api/registries/{id}/gifts
     * Add a gift to a registry
     */
    public function addGift(CreateGiftRequest $request, GiftRegistry $registry): JsonResponse
    {
        $this->authorizeOwner($request, $registry);

        $gift = $this->registryService->addGift($registry, $request->validated());

        return ApiResponse::success('Gift added successfully.', $gift, 201);
    }

    /**
     * PUT /api/registries/{registryId}/gifts/{giftId}
     * Update a gift
     */
    public function updateGift(CreateGiftRequest $request, GiftRegistry $registry, Gift $gift): JsonResponse
    {
        $this->authorizeOwner($request, $registry);
        $this->authorizeGift($gift, $registry);

        $gift = $this->registryService->updateGift($gift, $request->validated());

        return ApiResponse::success('Gift updated successfully.', $gift);
    }

    /**
     * DELETE /api/registries/{registryId}/gifts/{giftId}
     * Delete a gift
     */
    public function deleteGift(Request $request, GiftRegistry $registry, Gift $gift): JsonResponse
    {
        $this->authorizeOwner($request, $registry);
        $this->authorizeGift($gift, $registry);

        $this->registryService->deleteGift($gift);

        return ApiResponse::success('Gift deleted successfully.');
    }

    /**
     * GET /api/registries/{registryId}/gifts/{giftId}/contributors
     * Get contributors for a gift
     */
    public function contributors(Request $request, GiftRegistry $registry, Gift $gift): JsonResponse
    {
        $this->authorizeOwner($request, $registry);
        $this->authorizeGift($gift, $registry);

        $contributors = $this->registryService->getContributors($gift);

        return ApiResponse::success('Contributors retrieved.', $contributors);
    }

    /* ────────────────────────────────────────────
     | PUBLIC ENDPOINTS (no auth)
     ──────────────────────────────────────────── */

    /**
     * GET /api/public/registries/{token}
     * Get public registry by share token
     */
    public function publicShow(string $token): JsonResponse
    {
        $registry = $this->registryService->findByShareToken($token);

        if (!$registry) {
            return ApiResponse::notFound('Registry not found or is not public.');
        }

        return ApiResponse::success('Registry retrieved.', $registry);
    }

    /**
     * POST /api/public/registries/{token}/gifts/{giftId}/contribute
     * Contribute to a gift (public)
     */
    public function contribute(ContributeRequest $request, string $token, Gift $gift): JsonResponse
    {
        $registry = $this->registryService->findByShareToken($token);

        if (!$registry) {
            return ApiResponse::notFound('Registry not found or is not public.');
        }

        if ($gift->registry_id !== $registry->id) {
            return ApiResponse::error('Gift does not belong to this registry.');
        }

        if ($request->amount > 5000000 && empty($request->bvn)) {
            return ApiResponse::error('BVN is required for contributions above ₦5,000,000.', [
                'bvn' => ['BVN is required for amounts above ₦5,000,000.']
            ], 422);
        }

        try {
            $result = $this->registryService->contribute($gift, $request->validated());

            return ApiResponse::success('Contribution initiated. Complete payment to confirm.', $result, 201);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    /* ────────────────────────────────────────────
     | HELPERS
     ──────────────────────────────────────────── */

    private function authorizeOwner(Request $request, GiftRegistry $registry): void
    {
        if ($registry->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to access this registry.');
        }
    }

    private function authorizeGift(Gift $gift, GiftRegistry $registry): void
    {
        if ($gift->registry_id !== $registry->id) {
            abort(404, 'Gift not found in this registry.');
        }
    }



    /**
     * GET /api/gifts/catalog
     * Public catalog of available gifts (authenticated)
     */
    public function catalog(Request $request): JsonResponse
    {
        $query = CatalogGift::query();

        if ($search = $request->query('search')) {
            $query->where('name', 'ilike', "%{$search}%")
                ->orWhere('category', 'ilike', "%{$search}%");
        }

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        $gifts = $query->latest()->paginate(12);

        return ApiResponse::success('Catalog gifts retrieved.', $gifts);
    }

    public function publicIndex(): JsonResponse
    {
        $registries = GiftRegistry::where('is_public', true)
            ->with([
                'gifts' => function ($q) {
                    $q->limit(5);
                }
            ])
            ->latest()
            ->paginate(20);
        return ApiResponse::success('Registries retrieved.', $registries);
    }
}
