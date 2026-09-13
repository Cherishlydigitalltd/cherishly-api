<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\GiftRegistry;
use App\Models\MonetaryContribution;
use App\Models\MonetaryGift;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        $stats = [
            'total_users' => User::count(),
            'active_users' => User::where('is_active', true)->count(),
            'total_registries' => GiftRegistry::count(),
            'public_registries' => GiftRegistry::where('is_public', true)->count(),
            'total_monetary_gifts' => MonetaryGift::count(),
            'total_contributions' => Contribution::where('payment_status', 'successful')->count(),
            'total_donations' => MonetaryContribution::where('payment_status', 'successful')->count(),
            'total_revenue' => Contribution::where('payment_status', 'successful')->sum('fee_amount'),
            'total_revenue_monetary' => MonetaryContribution::where('payment_status', 'successful')->sum('fee_amount'),
            'total_gross_contributions' => Contribution::where('payment_status', 'successful')->sum('gross_amount'),
            'total_gross_donations' => MonetaryContribution::where('payment_status', 'successful')->sum('gross_amount'),
            'new_users_this_month' => User::whereMonth('created_at', now()->month)->count(),
            'new_registries_this_month' => GiftRegistry::whereMonth('created_at', now()->month)->count(),
        ];

        return ApiResponse::success('Dashboard stats retrieved.', $stats);
    }

    public function recentActivity(): JsonResponse
    {
        $contributions = Contribution::with('gift.registry.user')
            ->where('payment_status', 'successful')
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn($c) => [
                'type' => 'gift_contribution',
                'amount' => $c->gross_amount ?? $c->amount,
                'net_amount' => $c->net_amount ?? $c->amount,
                'fee_amount' => $c->fee_amount ?? 0,
                'fee_bearer' => $c->fee_bearer ?? 'gifter',
                'donor' => $c->donor_name,
                'gift' => $c->gift?->name,
                'registry' => $c->gift?->registry?->name,
                'owner' => $c->gift?->registry?->user?->full_name,
                'created_at' => $c->created_at,
            ]);

        $donations = MonetaryContribution::with('monetaryGift.user')
            ->where('payment_status', 'successful')
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn($c) => [
                'type' => 'monetary_contribution',
                'amount' => $c->gross_amount ?? $c->amount,
                'net_amount' => $c->net_amount ?? $c->amount,
                'fee_amount' => $c->fee_amount ?? 0,
                'fee_bearer' => $c->fee_bearer ?? 'gifter',
                'donor' => $c->donor_name,
                'gift' => $c->monetaryGift?->title,
                'owner' => $c->monetaryGift?->user?->full_name,
                'created_at' => $c->created_at,
            ]);

        $activity = $contributions->merge($donations)
            ->sortByDesc('created_at')
            ->take(15)
            ->values();

        return ApiResponse::success('Recent activity retrieved.', $activity);
    }

    public function getGlobalFeeRate(): JsonResponse
    {
        $rate = DB::table('settings')->where('key', 'transaction_fee_rate')->value('value');
        return ApiResponse::success('Fee rate retrieved.', ['rate' => (float) ($rate ?? 2.5)]);
    }

    public function updateGlobalFeeRate(Request $request): JsonResponse
    {
        $request->validate([
            'rate' => ['required', 'numeric', 'min:0', 'max:10'],
        ]);

        DB::table('settings')
            ->where('key', 'transaction_fee_rate')
            ->update(['value' => (string) $request->rate, 'updated_at' => now()]);

        return ApiResponse::success('Global fee rate updated successfully.');
    }
}
