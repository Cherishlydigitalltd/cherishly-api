<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Jobs\SendMarketplaceEmail;
use App\Models\Vendor;
use App\Models\VendorOrder;
use App\Models\VendorProduct;
use App\Services\MarketplaceService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Vendor approvals, item approvals, orders, payouts and the commission rate. */
class AdminVendorController extends Controller
{
    public function __construct(
        private MarketplaceService $marketplace,
        private NotificationService $notifications,
    ) {
    }

    /** GET /admin/marketplace/summary: counts for the sidebar badges. */
    public function summary(): JsonResponse
    {
        return ApiResponse::success('Summary retrieved.', [
            'vendors_pending' => Vendor::where('status', 'pending')->count(),
            'products_pending' => VendorProduct::where(fn ($q) => $q->where('status', 'pending')->orWhereNotNull('pending_changes'))
                ->whereHas('vendor', fn ($q) => $q->whereIn('status', ['pending', 'approved']))->count(),
            'orders_disputed' => VendorOrder::where('status', 'disputed')->count(),
            'payouts_failed' => VendorOrder::where('payout_status', 'failed')->count(),
            'commission_rate' => $this->marketplace->commissionRate(),
        ]);
    }

    /* ── Vendors ── */

    public function vendors(Request $request): JsonResponse
    {
        $vendors = Vendor::with('user:id,first_name,last_name,email')
            ->withCount('products')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('business_name', 'ilike', "%{$s}%")->orWhere('email', 'ilike', "%{$s}%")
                ->orWhere('contact_name', 'ilike', "%{$s}%")->orWhere('phone', 'ilike', "%{$s}%")))
            ->latest()
            ->paginate(20);

        return ApiResponse::success('Vendors retrieved.', $vendors);
    }

    public function vendor(Vendor $vendor): JsonResponse
    {
        $vendor->load('user:id,first_name,last_name,email,phone')->loadCount('products');
        $wallet = \App\Models\Wallet::where('user_id', $vendor->user_id)->first();
        $vendor->setAttribute('identity', $vendor->identity() + [
            'wallet_id' => $wallet?->id,
            'bvn_verified_at' => $wallet?->bvn_verified ? $wallet->bvn_verified_at : null,
        ]);
        return ApiResponse::success('Vendor retrieved.', [
            'vendor' => $vendor,
            'products' => $vendor->products()->latest()->get(),
            'orders' => $vendor->orders()->latest()->limit(20)->get(),
        ]);
    }

    /** PUT /admin/vendors/{vendor}/status: approve, reject, suspend or reinstate. */
    public function setVendorStatus(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected,suspended'],
            'reason' => ['required_if:status,rejected,suspended', 'nullable', 'string', 'max:500'],
        ]);
        if ($vendor->status === $data['status']) {
            return ApiResponse::error('The vendor is already ' . $data['status'] . '.', statusCode: 422);
        }

        $vendor->update([
            'status' => $data['status'],
            'status_reason' => $data['reason'] ?? null,
            'approved_at' => $data['status'] === 'approved' ? ($vendor->approved_at ?? now()) : $vendor->approved_at,
        ]);

        [$title, $body] = match ($data['status']) {
            'approved' => ['You’re approved!', 'Your vendor account on Cherishly is approved. Your approved items now show to celebrants.'],
            'rejected' => ['Vendor application not approved', "Your vendor application wasn’t approved: {$data['reason']}. You can update your details and apply again."],
            'suspended' => ['Vendor account suspended', "Your vendor account has been suspended: {$data['reason']}. Your items are hidden. Please contact support."],
        };
        $this->notifications->create($vendor->user, 'vendor', $title, $body, '/vendor', '🏪');
        SendMarketplaceEmail::dispatch($vendor->email, $vendor->contact_name, $title, [$body], 'OPEN VENDOR PORTAL',
            config('app.frontend_url') . '/vendor');

        return ApiResponse::success('Vendor updated.', $vendor->fresh());
    }

    /* ── Items ── */

    public function products(Request $request): JsonResponse
    {
        $products = VendorProduct::with('vendor:id,business_name,status')
            ->when($request->query('status'), fn ($q, $s) => $s === 'review'
                ? $q->where(fn ($q) => $q->where('status', 'pending')->orWhereNotNull('pending_changes'))
                    ->whereHas('vendor', fn ($q) => $q->whereIn('status', ['pending', 'approved']))
                : $q->where('status', $s))
            ->when($request->query('vendor_id'), fn ($q, $id) => $q->where('vendor_id', $id))
            ->when($request->query('search'), fn ($q, $s) => $q->where('name', 'ilike', "%{$s}%"))
            ->oldest('updated_at')
            ->paginate(20);

        return ApiResponse::success('Items retrieved.', $products);
    }

    /** PUT /admin/vendor-products/{product}/review: approve or reject a new item or an edit. */
    public function review(Request $request, VendorProduct $product): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:500'],
        ]);
        $isEdit = $product->status === 'approved' && $product->pending_changes;
        if ($product->status !== 'pending' && !$isEdit) {
            return ApiResponse::error('This item has nothing waiting for review.', statusCode: 422);
        }

        if ($data['decision'] === 'approve') {
            $product->update(($product->pending_changes ?? []) + [
                'status' => 'approved',
                'pending_changes' => null,
                'status_reason' => null,
            ]);
            $title = $isEdit ? 'Changes approved' : 'Item approved';
            $body = "“{$product->name}” " . ($isEdit ? 'changes are now live.' : 'is now live for celebrants.');
        } else {
            // A rejected edit leaves the live item as it was.
            $product->update($isEdit
                ? ['pending_changes' => null, 'status_reason' => $data['reason']]
                : ['status' => 'rejected', 'status_reason' => $data['reason']]);
            $title = $isEdit ? 'Changes not approved' : 'Item not approved';
            $body = "“{$product->name}”: {$data['reason']}";
        }

        $this->notifications->create($product->vendor->user, 'vendor', $title, $body, "/vendor/products/{$product->id}", '🏷️');
        return ApiResponse::success($title . '.', $product->fresh());
    }

    /* ── Orders ── */

    public function orders(Request $request): JsonResponse
    {
        $orders = VendorOrder::with(['vendor:id,business_name', 'celebrant:id,first_name,last_name,email'])
            ->when($request->query('status'), fn ($q, $s) => $s === 'payout_failed'
                ? $q->where('payout_status', 'failed')
                : $q->where('status', $s))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('reference', 'ilike', "%{$s}%")->orWhere('item_name', 'ilike', "%{$s}%")))
            ->latest()
            ->paginate(20);

        return ApiResponse::success('Orders retrieved.', $orders);
    }

    public function order(VendorOrder $order): JsonResponse
    {
        return ApiResponse::success('Order retrieved.', $order->load([
            'vendor', 'celebrant:id,first_name,last_name,email,phone', 'registry:id,name',
        ]));
    }

    /** PUT /admin/vendor-orders/{order}/resolve: settle a reported problem. */
    public function resolve(Request $request, VendorOrder $order): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', 'in:vendor,celebrant'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $order = $this->marketplace->resolve($order, $data['outcome'], $data['note'] ?? null);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), statusCode: 422);
        }
        return ApiResponse::success($data['outcome'] === 'vendor' ? 'Vendor will be paid.' : 'Celebrant refunded to their wallet.', $order);
    }

    /** POST /admin/vendor-orders/{order}/retry-payout */
    public function retryPayout(VendorOrder $order): JsonResponse
    {
        if ($order->status !== 'completed' || $order->payout_status !== 'failed') {
            return ApiResponse::error('Only failed payouts can be retried.', statusCode: 422);
        }
        $order = $this->marketplace->payVendor($order);
        return $order->payout_status === 'processing'
            ? ApiResponse::success('Payout sent.', $order)
            : ApiResponse::error('The payout failed again. Check the vendor’s bank details.', statusCode: 422);
    }

    /* ── Commission ── */

    public function commission(): JsonResponse
    {
        return ApiResponse::success('Commission retrieved.', ['rate' => $this->marketplace->commissionRate()]);
    }

    /** New orders use the new rate; existing orders keep theirs. */
    public function updateCommission(Request $request): JsonResponse
    {
        $data = $request->validate(['rate' => ['required', 'numeric', 'min:0', 'max:50']]);
        DB::table('settings')->updateOrInsert(
            ['key' => 'vendor_commission_rate'],
            ['value' => (string) $data['rate'], 'updated_at' => now()],
        );
        Cache::forget('setting_vendor_commission_rate');

        return ApiResponse::success('Commission updated.', ['rate' => (float) $data['rate']]);
    }
}
