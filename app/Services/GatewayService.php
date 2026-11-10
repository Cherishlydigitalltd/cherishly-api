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
            ])->post("{$this->baseUrl}/api/Gateway/initialize", [
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
            ])->get("{$this->baseUrl}/api/Gateway/verify/{$reference}");

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

    /**
     * Ask the payout provider to send money to a bank account.
     *
     * Returns success=false with uncertain=true when we can't tell whether
     * the provider accepted the payout (timeout, provider error, duplicate
     * reference). Callers must NOT refund or retry with a new reference in
     * that case: the payout may already be on its way, and the webhook
     * will settle it. uncertain=false means the provider clearly refused
     * it (or we never reached it), so nothing was sent.
     */
    public function initiateWithdrawal(array $data): array
    {
        try {
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->connectTimeout(10)->timeout(20)
              ->post("{$this->baseUrl}/api/Payouts/initiate", [
                        'clientId' => $this->clientId,
                        'reference' => $data['reference'],
                        'amount' => $data['amount'],
                        'accountNumber' => $data['account_number'],
                        'accountName' => $data['account_name'],
                        'bankCode' => $data['bank_code'],
                        'bankName' => $data['bank_name'],
                        'narration' => $data['narration'] ?? 'Cherishly wallet withdrawal',
                        'callbackType' => 'withdrawal',
                    ]);

            if (!$response->successful()) {
                $status = $response->status();
                // 5xx: the provider broke, possibly after accepting it.
                // 408: it timed out on their side. 409: that reference
                // already exists, i.e. an earlier attempt got through.
                $uncertain = $status >= 500 || in_array($status, [408, 409], true);

                Log::error('Gateway withdrawal failed', [
                    'status' => $status,
                    'uncertain' => $uncertain,
                    'response' => $response->json(),
                    'reference' => $data['reference'],
                ]);
                return [
                    'success' => false,
                    'uncertain' => $uncertain,
                    'message' => $response->json('message') ?? 'Gateway error. Please try again.',
                ];
            }

            return [
                'success' => true,
                'message' => 'Withdrawal initiated successfully.',
                'data' => $response->json('data'),
            ];

        } catch (\Exception $e) {
            // Couldn't resolve or connect (cURL 6 / 7): the request never
            // left, so nothing was sent. Anything else (usually a timeout
            // after sending) might have gone through.
            $neverSent = (bool) preg_match('/cURL error (6|7):/', $e->getMessage());

            Log::error('Gateway connection failed', [
                'error' => $e->getMessage(),
                'uncertain' => !$neverSent,
                'reference' => $data['reference'] ?? null,
            ]);
            return [
                'success' => false,
                'uncertain' => !$neverSent,
                'message' => 'Unable to connect to payment gateway. Please try again.',
            ];
        }
    }

    /* ── Match BVN against bank account (synchronous) ── */

    public function verifyBvn(string $bvn, string $firstName, string $lastName, string $accountNumber = '', string $bankCode = ''): array
    {
        try {
            // Use match_bvn endpoint — synchronous, instant
            $response = Http::withHeaders([
                'X-API-Key' => $this->apiKey,
                'Accept' => 'application/json',
            ])->get("{$this->baseUrl}/api/Gateway/bvn/match", [
                        'account_number' => $accountNumber,
                        'bank_code' => $bankCode,
                        'bvn' => $bvn,
                    ]);

            if (!$response->successful()) {
                return ['success' => false, 'message' => 'BVN verification failed.'];
            }

            $matched = $response->json('data.matched');

            return [
                'success' => $matched === true,
                'message' => $matched ? 'BVN verified successfully.' : 'BVN does not match this account.',
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
            ])->get("{$this->baseUrl}/api/Gateway/banks/resolve", [
                        'account_number' => $accountNumber,
                        'bank_code' => $bankCode,
                    ]);

            if (!$response->successful()) {
                return ['success' => false, 'message' => 'Could not resolve bank account.'];
            }

            return [
                'success' => true,
                'account_name' => $response->json('data.accountName'),
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
            ])->get("{$this->baseUrl}/api/Gateway/banks");

            return $response->successful()
                ? $response->json('data', [])
                : [];

        } catch (\Exception $e) {
            Log::error('Get banks failed', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
