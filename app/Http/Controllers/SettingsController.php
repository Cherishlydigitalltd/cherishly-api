<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        return ApiResponse::success('Settings retrieved.', [
            'fee_bearer' => $user->fee_bearer ?? 'gifter',
            'transaction_fee_rate' => $user->transaction_fee_rate, // null = global rate
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