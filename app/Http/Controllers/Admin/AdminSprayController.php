<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\SprayTopup;
use App\Services\SprayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Spray top-ups (Finance) and the BVN limit for big sprayers. */
class AdminSprayController extends Controller
{
    public function topups(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'in:pending,successful,failed'],
            'event_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $query = SprayTopup::with([
            'guest:id,display_name,is_anonymous,email,loaded,sprayed,bvn_last4,bvn_full_name,bvn_verified_at',
            'event' => fn ($q) => $q->withTrashed()->select('id', 'user_id', 'title', 'spray_code'),
            'event.user:id,first_name,last_name,email',
        ])->latest('id');

        if ($request->filled('status')) {
            $query->where('payment_status', $request->query('status'));
        }
        if ($request->filled('event_id')) {
            $query->where('event_id', $request->integer('event_id'));
        }
        if ($request->filled('q')) {
            $q = '%' . strtolower(trim((string) $request->query('q'))) . '%';
            $query->where(fn ($w) => $w->whereRaw('LOWER(payment_reference) LIKE ?', [$q])
                ->orWhereHas('guest', fn ($g) => $g->whereRaw('LOWER(email) LIKE ?', [$q])->orWhereRaw('LOWER(display_name) LIKE ?', [$q])));
        }

        $paid = SprayTopup::where('payment_status', 'successful');

        return ApiResponse::success('Spray top-ups.', [
            'summary' => [
                'loaded' => (float) (clone $paid)->sum('amount'),
                'fees' => (float) (clone $paid)->sum('fee_amount'),
                'count' => (clone $paid)->count(),
                'events' => (clone $paid)->distinct()->count('event_id'),
            ],
            'topups' => $query->paginate(25),
        ]);
    }

    public function settings(SprayService $spray): JsonResponse
    {
        return ApiResponse::success('Spray settings.', [
            'bvn_threshold' => $spray->bvnThreshold(),
            'min_topup' => SprayService::MIN_TOPUP,
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['bvn_threshold' => ['required', 'numeric', 'min:0', 'max:1000000000']]);

        DB::table('settings')->updateOrInsert(
            ['key' => 'spray_bvn_threshold'],
            ['value' => (string) $data['bvn_threshold'], 'updated_at' => now()],
        );
        Cache::forget('setting_spray_bvn_threshold');

        return ApiResponse::success('Spray settings saved.');
    }
}
