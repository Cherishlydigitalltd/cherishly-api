<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Gift;
use App\Models\GiftRegistry;
use App\Models\Vendor;
use App\Services\MarketplaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The celebrant's side of a vendor item: its order, delivery address, taking
 * the cash instead, and confirming delivery.
 */
class GiftOrderController extends Controller
{
    public function __construct(private MarketplaceService $marketplace)
    {
    }

    /** GET /registries/{registry}/gifts/{gift}/order */
    public function show(Request $request, GiftRegistry $registry, Gift $gift): JsonResponse
    {
        $this->guard($request, $registry, $gift);

        $order = $gift->vendorOrder;
        return ApiResponse::success('Order retrieved.', [
            'gift' => $gift->load('vendor:id,business_name,logo,phone,delivery_states'),
            'order' => $order?->makeHidden(['commission_rate', 'commission_amount', 'vendor_amount', 'payout_reference']),
            'delivery_states' => $gift->vendor?->delivery_states ?? [],
            'saved_address' => $registry->only(['delivery_name', 'delivery_phone', 'delivery_address', 'delivery_state']),
            'auto_complete_days' => MarketplaceService::AUTO_COMPLETE_DAYS,
        ]);
    }

    /** PUT /registries/{registry}/gifts/{gift}/order/address */
    public function address(Request $request, GiftRegistry $registry, Gift $gift): JsonResponse
    {
        $this->guard($request, $registry, $gift);
        $data = $request->validate([
            'delivery_name' => ['required', 'string', 'max:200'],
            'delivery_phone' => ['required', 'string', 'max:30'],
            'delivery_address' => ['required', 'string', 'max:500'],
            'delivery_state' => ['required', Rule::in(Vendor::STATES)],
        ]);

        return $this->run(fn () => $this->marketplace->setAddress($this->order($gift), $data), 'Delivery address saved.');
    }

    /** POST /registries/{registry}/gifts/{gift}/take-cash */
    public function takeCash(Request $request, GiftRegistry $registry, Gift $gift): JsonResponse
    {
        $this->guard($request, $registry, $gift);
        return $this->run(fn () => $this->marketplace->takeCash($gift), 'The money has been added to your wallet.');
    }

    /** POST /registries/{registry}/gifts/{gift}/order/received */
    public function received(Request $request, GiftRegistry $registry, Gift $gift): JsonResponse
    {
        $this->guard($request, $registry, $gift);
        return $this->run(fn () => $this->marketplace->confirmReceived($this->order($gift)), 'Thanks for confirming!');
    }

    /** POST /registries/{registry}/gifts/{gift}/order/problem */
    public function problem(Request $request, GiftRegistry $registry, Gift $gift): JsonResponse
    {
        $this->guard($request, $registry, $gift);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        return $this->run(fn () => $this->marketplace->reportProblem($this->order($gift), $data['reason']),
            'Problem reported. Our team will contact you.');
    }

    private function order(Gift $gift)
    {
        $order = $gift->vendorOrder;
        if (!$order) {
            throw new \RuntimeException('This gift has no order yet.');
        }
        return $order;
    }

    private function run(callable $action, string $message): JsonResponse
    {
        try {
            return ApiResponse::success($message, $action());
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), statusCode: 422);
        }
    }

    private function guard(Request $request, GiftRegistry $registry, Gift $gift): void
    {
        abort_if($registry->user_id !== $request->user()->id, 403, 'You do not have permission to access this registry.');
        abort_if($gift->registry_id !== $registry->id || !$gift->is_vendor_item, 404);
    }
}
