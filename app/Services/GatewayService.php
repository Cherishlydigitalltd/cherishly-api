<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GatewayService
{
    private string $baseUrl;
    private string $apiKey;
    private string $clientId;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.gateway.url', ''), '/');
        $this->apiKey = config('services.gateway.api_key', '');
        $this->clientId = config('services.gateway.client_id', '');
    }

    /* ── Initialize Payment ── */

    public function initializePayment(array $data): array
    {
        try {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post("{$this->baseUrl}/api/gateway/initialize", [
                        'clientId' => $this->clientId,
                        'email' => $data['email'],
                        'name' => $data['name'] ?? null,
                        'amount' => $data['amount'],
                        'currency' => 'NGN',
                        'reference' => $data['reference'],
                        'callbackUrl' => $data['callback_url'] ?? config('services.gateway.callback_url'),
                        'metadata' => $data['metadata'] ?? [],
                    ]);

            if (!$response->successful()) {
                Log::error('Gateway payment initialization failed', [
                    'status' => $response->status(),
                    'response' => $response->json(),
                    'data' => $data,
                ]);
                return [
                    'success' => false,
                    'message' => $response->json('statusMessage') ?? 'Gateway error. Please try again.',
                ];
            }

            return [
                'success' => true,
                'payment_url' => $response->json('data.paymentUrl'),
                'reference' => $response->json('data.reference'),
                'access_code' => $response->json('data.accessCode'),
            ];

        } catch (\Exception $e) {
            Log::error('Gateway connection failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Unable to connect to payment gateway. Please try again.'];
        }
    }

    /* ── Verify Payment ── */

    public function verifyPayment(string $reference): array
    {
        try {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
                'Accept' => 'application/json',
            ])->get("{$this->baseUrl}/api/gateway/verify/{$reference}");

            if (!$response->successful()) {
                return ['success' => false, 'message' => 'Could not verify payment.'];
            }

            $data = $response->json('data');

            return [
                'success' => $data['status'] ?? false,
                'payment_status' => $data['paymentStatus'] ?? 'pending',
                'amount' => $data['amount'] ?? 0,
                'reference' => $data['reference'] ?? $reference,
                'metadata' => $data['metadata'] ?? [],
                'paid_at' => $data['paidAt'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('Gateway verify failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Payment verification unavailable.'];
        }
    }

    /* ── Initiate Withdrawal ── */

    public function initiateWithdrawal(array $data): array
    {
        try {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post("{$this->baseUrl}/api/payouts/initiate", [
                        'reference' => $data['reference'],
                        'amount' => $data['amount'],
                        'account_number' => $data['account_number'],
                        'account_name' => $data['account_name'],
                        'bank_code' => $data['bank_code'],
                        'bank_name' => $data['bank_name'],
                        'narration' => $data['narration'] ?? 'Cherishly wallet withdrawal',
                        'callback_type' => 'withdrawal',
                    ]);

            if (!$response->successful()) {
                Log::error('Gateway withdrawal failed', [
                    'status' => $response->status(),
                    'response' => $response->json(),
                    'data' => $data,
                ]);
                return [
                    'success' => false,
                    'message' => $response->json('message') ?? 'Gateway error. Please try again.',
                ];
            }

            return [
                'success' => true,
                'message' => 'Withdrawal initiated successfully.',
                'data' => $response->json('data'),
            ];

        } catch (\Exception $e) {
            Log::error('Gateway connection failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Unable to connect to payment gateway. Please try again.'];
        }
    }

    /* ── Verify BVN ── */

    public function verifyBvn(string $bvn, string $firstName, string $lastName): array
    {
        try {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
            ])->post("{$this->baseUrl}/api/kyc/bvn/verify", [
                        'bvn' => $bvn,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                    ]);

            return [
                'success' => $response->successful() && $response->json('data.verified'),
                'message' => $response->json('message') ?? 'BVN verification failed.',
            ];

        } catch (\Exception $e) {
            Log::error('BVN verification failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'BVN verification unavailable.'];
        }
    }

    /* ── Resolve Bank Account ── */

    public function resolveBankAccount(string $accountNumber, string $bankCode): array
    {
        try {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
                'Accept' => 'application/json',
            ])->get("{$this->baseUrl}/api/gateway/banks/resolve", [
                        'account_number' => $accountNumber,
                        'bank_code' => $bankCode,
                    ]);

            if (!$response->successful()) {
                return ['success' => false, 'message' => 'Could not resolve bank account.'];
            }

            return [
                'success' => true,
                'account_name' => $response->json('data.account_name'),
            ];

        } catch (\Exception $e) {
            Log::error('Bank resolve failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Bank resolution unavailable.'];
        }
    }

    /* ── Get Banks ── */

    public function getBanks(): array
    {
        try {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
                'Accept' => 'application/json',
            ])->get("{$this->baseUrl}/api/gateway/banks");

            return $response->successful()
                ? $response->json('data', [])
                : [];

        } catch (\Exception $e) {
            Log::error('Get banks failed', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
