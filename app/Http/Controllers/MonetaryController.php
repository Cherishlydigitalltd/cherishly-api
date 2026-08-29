<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Monetary\ContributeMonetaryRequest;
use App\Http\Requests\Monetary\CreateMonetaryRequest;
use App\Http\Requests\Monetary\UpdateMonetaryRequest;
use App\Models\MonetaryGift;
use App\Services\MonetaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonetaryController extends Controller
{
    public function __construct(
        private MonetaryService $monetaryService
    ) {
    }

    /**
     * GET /api/monetary
     */
    public function index(Request $request): JsonResponse
    {
        $gifts = $this->monetaryService->getUserMonetaryGifts($request->user());

        return ApiResponse::success('Monetary gifts retrieved.', $gifts);
    }

    /**
     * POST /api/monetary
     */
    public function store(CreateMonetaryRequest $request): JsonResponse
    {
        $gift = $this->monetaryService->create($request->user(), $request->validated());

        return ApiResponse::success('Monetary gift created successfully.', $gift, 201);
    }

    /**
     * GET /api/monetary/{id}
     */
    public function show(Request $request, MonetaryGift $monetary): JsonResponse
    {
        $this->authorizeOwner($request, $monetary);

        $monetary->loadCount('successfulContributions');

        return ApiResponse::success('Monetary gift retrieved.', $monetary);
    }

    /**
     * PUT /api/monetary/{id}
     */
    public function update(UpdateMonetaryRequest $request, MonetaryGift $monetary): JsonResponse
    {
        $this->authorizeOwner($request, $monetary);

        $monetary = $this->monetaryService->update($monetary, $request->validated());

        return ApiResponse::success('Monetary gift updated successfully.', $monetary);
    }

    /**
     * DELETE /api/monetary/{id}
     */
    public function destroy(Request $request, MonetaryGift $monetary): JsonResponse
    {
        $this->authorizeOwner($request, $monetary);

        $this->monetaryService->delete($monetary);

        return ApiResponse::success('Monetary gift deleted successfully.');
    }

    /**
     * GET /api/monetary/{id}/contributors
     */
    public function contributors(Request $request, MonetaryGift $monetary): JsonResponse
    {
        $this->authorizeOwner($request, $monetary);

        $contributors = $this->monetaryService->getContributors($monetary);

        return ApiResponse::success('Contributors retrieved.', $contributors);
    }

    /**
     * GET /api/public/monetary/{token}
     */
    public function publicShow(string $token): JsonResponse
    {
        $gift = $this->monetaryService->findByShareToken($token);

        if (!$gift) {
            return ApiResponse::notFound('Monetary gift not found or is no longer active.');
        }

        return ApiResponse::success('Monetary gift retrieved.', $gift);
    }

    /**
     * POST /api/public/monetary/{token}/contribute
     */
    public function contribute(ContributeMonetaryRequest $request, string $token): JsonResponse
    {
        $gift = $this->monetaryService->findByShareToken($token);

        if (!$gift) {
            return ApiResponse::notFound('Monetary gift not found or is no longer active.');
        }

        if ($request->amount > 5000000 && empty($request->bvn)) {
            return ApiResponse::validationError([
                'bvn' => ['BVN is required for contributions above ₦5,000,000.']
            ]);
        }

        try {
            $result = $this->monetaryService->contribute($gift, $request->validated());
            return ApiResponse::success('Contribution initiated. Complete payment to confirm.', $result, 201);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 500);
        }
    }

    /* ── Helper ── */

    private function authorizeOwner(Request $request, MonetaryGift $gift): void
    {
        if ($gift->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to access this monetary gift.');
        }
    }
}
