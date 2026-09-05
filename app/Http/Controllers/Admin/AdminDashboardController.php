<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\GiftRegistry;
use App\Models\MonetaryContribution;
use App\Models\MonetaryGift;
use App\Models\User;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
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
            'total_revenue' => Contribution::where('payment_status', 'successful')->sum('amount'),
            'total_donations' => MonetaryContribution::where('payment_status', 'successful')->sum('amount'),
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
                'amount' => $c->amount,
                'donor' => $c->is_anonymous ? 'Anonymous' : $c->donor_name,
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
                'amount' => $c->amount,
                'donor' => $c->is_anonymous ? 'Anonymous' : $c->donor_name,
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
}