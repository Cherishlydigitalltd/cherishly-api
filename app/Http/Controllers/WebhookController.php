<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Services\GiftRegistryService;
use App\Services\MonetaryService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        private GiftRegistryService $registryService,
        private MonetaryService $monetaryService,
        private WalletService $walletService
    ) {
    }

    public function payment(Request $request): JsonResponse
    {
        if (!$this->verifySignature($request)) {
            Log::warning('Webhook: invalid signature', ['ip' => $request->ip()]);
            return ApiResponse::unauthorized('Invalid webhook signature.');
        }

        $payload = $request->json()->all();
        Log::info('Webhook received', $payload);

        if (empty($payload['reference']) || empty($payload['status']) || empty($payload['type'])) {
            return ApiResponse::error('Invalid webhook payload.', null, 400);
        }

        $reference = $payload['reference'];
        $status = $payload['status'];
        $type = $payload['type'];
        $meta = $payload['meta'] ?? [];

        $status = match ($status) {
            'success', 'successful', 'completed' => 'successful',
            'failed', 'failure', 'declined' => 'failed',
            default => 'failed',
        };

        $handled = match ($type) {
            'gift_contribution' => $this->handleGiftContribution($reference, $status, $meta),
            'monetary_contribution' => $this->handleMonetaryContribution($reference, $status, $meta),
            'withdrawal' => $this->handleWithdrawal($reference, $status),
            default => false,
        };

        if (!$handled) {
            Log::warning('Webhook: unhandled or not found', ['type' => $type, 'reference' => $reference]);
            return ApiResponse::error('Reference not found.', null, 404);
        }

        return ApiResponse::success('Webhook processed successfully.');
    }

    public function test(Request $request): JsonResponse
    {
        if (app()->isProduction()) {
            return ApiResponse::notFound('Not found.');
        }

        $request->validate([
            'reference' => ['required', 'string'],
            'status' => ['required', 'in:successful,failed'],
            'type' => ['required', 'in:gift_contribution,monetary_contribution,withdrawal'],
        ]);

        $handled = match ($request->type) {
            'gift_contribution' => $this->handleGiftContribution($request->reference, $request->status, []),
            'monetary_contribution' => $this->handleMonetaryContribution($request->reference, $request->status, []),
            'withdrawal' => $this->handleWithdrawal($request->reference, $request->status),
            default => false,
        };

        return $handled
            ? ApiResponse::success('Test webhook processed.')
            : ApiResponse::error('Reference not found.');
    }

    private function handleGiftContribution(string $reference, string $status, array $meta): bool
    {
        try {
            return $this->registryService->confirmPayment($reference, $status, $meta);
        } catch (\Exception $e) {
            Log::error('Webhook: gift contribution failed', ['reference' => $reference, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function handleMonetaryContribution(string $reference, string $status, array $meta): bool
    {
        try {
            return $this->monetaryService->confirmPayment($reference, $status, $meta);
        } catch (\Exception $e) {
            Log::error('Webhook: monetary contribution failed', ['reference' => $reference, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function handleWithdrawal(string $reference, string $status): bool
    {
        try {
            return $this->walletService->confirmWithdrawal($reference, $status);
        } catch (\Exception $e) {
            Log::error('Webhook: withdrawal failed', ['reference' => $reference, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function verifySignature(Request $request): bool
    {
        $secret = config('services.gateway.webhook_secret');
        $signature = $request->header('X-Webhook-Signature');

        if (!$secret || !$signature) {
            return !app()->isProduction();
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        return hash_equals($expected, $signature);
    }
}
