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

        $effectiveTransactionRate = $user->transaction_fee_rate
            ?? (float) (DB::table('settings')->where('key', 'transaction_fee_rate')->value('value') ?? 2.5);

        $effectiveWithdrawalRate = $user->withdrawal_fee_rate
            ?? (float) (DB::table('settings')->where('key', 'withdrawal_fee_rate')->value('value') ?? 1.0);

        $withdrawalMin = (float) (DB::table('settings')->where('key', 'withdrawal_fee_min')->value('value') ?? 50);
        $withdrawalCap = (float) (DB::table('settings')->where('key', 'withdrawal_fee_cap')->value('value') ?? 2000);

        return ApiResponse::success('Settings retrieved.', [
            'fee_bearer' => $user->fee_bearer ?? 'gifter',
            'transaction_fee_rate' => $effectiveTransactionRate,
            'withdrawal_fee_rate' => $effectiveWithdrawalRate,
            'withdrawal_fee_min' => $withdrawalMin,
            'withdrawal_fee_cap' => $withdrawalCap,
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