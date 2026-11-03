<?php

namespace App\Http\Controllers\Vendor;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\VendorOrder;
use App\Services\MarketplaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Orders for the vendor's items, and moving them along to delivery. */
class VendorOrderController extends Controller
{
    public function __construct(private MarketplaceService $marketplace)
    {
    }

    /** GET /vendor/orders?status= */
    public function index(Request $request): JsonResponse
    {
        $vendor = $request->attributes->get('vendor');
        $orders = VendorOrder::where('vendor_id', $vendor->id)
            ->where('status', '!=', 'awaiting_address') // not the vendor's yet
            ->when($request->query('status'), fn ($q, $status) => match ($status) {
                'new' => $q->where('status', 'placed'),
                'active' => $q->whereIn('status', ['accepted', 'dispatched', 'delivered', 'disputed']),
                'done' => $q->whereIn('status', ['completed', 'cancelled']),
                default => $q->where('status', $status),
            })
            ->latest('placed_at')
            ->paginate(20);

        return ApiResponse::success('Orders retrieved.', $orders);
    }

    /** GET /vendor/orders/{order} */
    public function show(Request $request, VendorOrder $order): JsonResponse
    {
        $this->own($request, $order);
        return ApiResponse::success('Order retrieved.', $order->load('registry:id,name'));
    }

    public function accept(Request $request, VendorOrder $order): JsonResponse
    {
        $this->own($request, $order);
        return $this->run(fn () => $this->marketplace->accept($order), 'Order accepted.');
    }

    public function decline(Request $request, VendorOrder $order): JsonResponse
    {
        $this->own($request, $order);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        return $this->run(fn () => $this->marketplace->decline($order, $data['reason']), 'Order declined. The celebrant has been refunded.');
    }

    public function dispatch(Request $request, VendorOrder $order): JsonResponse
    {
        $this->own($request, $order);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        return $this->run(fn () => $this->marketplace->dispatch($order, $data['note'] ?? null), 'Marked as on its way.');
    }

    public function delivered(Request $request, VendorOrder $order): JsonResponse
    {
        $this->own($request, $order);
        return $this->run(fn () => $this->marketplace->markDelivered($order),
            'Marked as delivered. You’ll be paid once the celebrant confirms, or automatically in '
                . MarketplaceService::AUTO_COMPLETE_DAYS . ' days.');
    }

    private function run(callable $action, string $message): JsonResponse
    {
        try {
            return ApiResponse::success($message, $action());
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), statusCode: 422);
        }
    }

    private function own(Request $request, VendorOrder $order): void
    {
        abort_if($order->vendor_id !== $request->attributes->get('vendor')->id || $order->status === 'awaiting_address', 404);
    }
}
