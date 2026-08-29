<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Services\GatewayService;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function __construct(
        private GatewayService $gatewayService
    ) {
    }

    /**
     * GET /api/payment/verify/{reference}
     */
    public function verify(string $reference): JsonResponse
    {
        try {
            $result = $this->gatewayService->verifyPayment($reference);
            return ApiResponse::success('Payment verified.', $result);
        } catch (\Exception $e) {
            return ApiResponse::error('Verification failed. ' . $e->getMessage(), null, 500);
        }
    }
}
