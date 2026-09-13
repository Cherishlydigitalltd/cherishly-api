<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $effectiveRate = $user->transaction_fee_rate
            ?? (float) (DB::table('settings')->where('key', 'transaction_fee_rate')->value('value') ?? 2.5);

        return ApiResponse::success('Settings retrieved.', [
            'fee_bearer' => $user->fee_bearer ?? 'gifter',
            'transaction_fee_rate' => $effectiveRate,
        ]);
    }

    public function updateFeeBearer(Request $request): JsonResponse
    {
        $request->validate([
            'fee_bearer' => ['required', 'in:gifter,owner'],
        ]);

        $request->user()->update(['fee_bearer' => $request->fee_bearer]);

        return ApiResponse::success('Fee bearer updated successfully.');
    }
}