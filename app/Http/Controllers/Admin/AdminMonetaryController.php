<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\MonetaryGift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMonetaryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MonetaryGift::with('user:id,first_name,last_name,email')
            ->withCount('successfulContributions')
            ->latest();

        if ($search = $request->query('search')) {
            $query->where('title', 'ilike', "%{$search}%");
        }

        return ApiResponse::success('Monetary gifts retrieved.', $query->paginate(20));
    }

    public function show(MonetaryGift $monetaryGift): JsonResponse
    {
        $monetaryGift->load([
            'user:id,first_name,last_name,email',
            'successfulContributions' => function ($q) {
                $q->select(['id', 'monetary_gift_id', 'donor_name', 'donor_email', 'amount', 'is_anonymous', 'payment_reference', 'created_at'])
                    ->latest();
            }
        ]);
        return ApiResponse::success('Monetary gift retrieved.', $monetaryGift);
    }

    public function destroy(MonetaryGift $monetaryGift): JsonResponse
    {
        $monetaryGift->delete();
        return ApiResponse::success('Monetary gift deleted.');
    }
}