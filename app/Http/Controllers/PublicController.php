<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Services\StatsService;
use Illuminate\Http\JsonResponse;

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
}