<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\ContributionBvnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** The amount above which a gift needs a verified BVN. */
class AdminBvnSettingsController extends Controller
{
    public function show(ContributionBvnService $bvn): JsonResponse
    {
        return ApiResponse::success('Contribution BVN limit.', ['threshold' => $bvn->threshold()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['threshold' => ['required', 'numeric', 'min:0', 'max:1000000000']]);

        DB::table('settings')->updateOrInsert(
            ['key' => 'contribution_bvn_threshold'],
            ['value' => (string) $data['threshold'], 'updated_at' => now()],
        );
        Cache::forget('setting_contribution_bvn_threshold');

        return ApiResponse::success('BVN limit saved.', ['threshold' => (float) $data['threshold']]);
    }
}
