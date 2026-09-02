<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletService
{
    public function __construct(
        private GatewayService $gatewayService
    ) {
    }

    /* ── Get wallet ── */

    public function getWallet(User $user): Wallet
    {
        return $user->wallet()->firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0, 'total_received' => 0]
        );
    }

    /* ── Get transactions ── */

    public function getTransactions(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = $user->wallet->transactions()->latest();

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate(20);
    }

    /* ── Get summary ── */

    public function getSummary(User $user): array
    {
        $wallet = $this->getWallet($user);

        return [
            'balance' => $wallet->balance,
            'total_received' => $wallet->total_received,
            'bank_details' => [
                'bank_name' => $wallet->bank_name,
                'account_number' => $wallet->account_number,
                'account_name' => $wallet->account_name,
                'bank_code' => $wallet->bank_code,
                'bvn_verified' => $wallet->bvn_verified ?? false,
            ],
        ];
    }

    /* ── Update bank details ── */

    public function updateBankDetails(User $user, array $data): array
    {
        // 1. Resolve account name from gateway
        $resolved = $this->gatewayService->resolveBankAccount(
            $data['account_number'],
            $data['bank_code']
        );

        if (!$resolved['success']) {
            return ['success' => false, 'message' => $resolved['message']];
        }

        $resolvedName = strtoupper($resolved['account_name']);

        // 2. Name match check — resolved name must contain user's first or last name
        $firstName = strtoupper($user->first_name);
        $lastName = strtoupper($user->last_name);

        $nameMatches = str_contains($resolvedName, $firstName)
            || str_contains($resolvedName, $lastName);

        if (!$nameMatches) {
            Log::warning('Bank account name mismatch', [
                'user_id' => $user->id,
                'resolved_name' => $resolvedName,
                'user_name' => "$firstName $lastName",
            ]);

            return [
                'success' => false,
                'message' => 'The account name does not match your registered name. Please use a bank account in your name.',
            ];
        }

        // 3. Store BVN encrypted (verification to be done via KYC provider later)
        $wallet = $this->getWallet($user);
        $wallet->update([
            'bank_name' => $data['bank_name'],
            'account_number' => $data['account_number'],
            'account_name' => $resolvedName,
            'bank_code' => $data['bank_code'],
            'bvn_verified' => false, // will be updated when KYC provider verifies
            'bvn_encrypted' => !empty($data['bvn'])
                ? \Illuminate\Support\Facades\Crypt::encryptString($data['bvn'])
                : null,
        ]);

        return [
            'success' => true,
            'wallet' => $wallet->fresh(),
        ];
    }

    /* ── Withdraw ── */

    public function withdraw(User $user, float $amount): array
    {
        $wallet = $this->getWallet($user);

        // Validate minimum withdrawal
        $minWithdrawal = (float) setting('min_withdrawal', 1000);
        if ($amount < $minWithdrawal) {
            return [
                'success' => false,
                'message' => 'Minimum withdrawal amount is ₦' . number_format($minWithdrawal, 2),
            ];
        }

        // Validate sufficient balance
        if ($wallet->balance < $amount) {
            return ['success' => false, 'message' => 'Insufficient balance.'];
        }

        // Daily withdrawal limit
        $limitCheck = $this->checkDailyWithdrawalLimit($wallet, $amount);
        if (!$limitCheck['success'])
            return $limitCheck;

        // Validate bank details exist
        if (!$wallet->account_number || !$wallet->bank_code) {
            return [
                'success' => false,
                'message' => 'Please add your bank details before withdrawing.',
            ];
        }

        return DB::transaction(function () use ($wallet, $user, $amount) {
            $reference = 'WD-' . strtoupper(uniqid());

            // Deduct balance immediately (hold funds)
            $wallet->decrement('balance', $amount);

            // Create pending transaction
            $transaction = $wallet->transactions()->create([
                'user_id' => $user->id,
                'type' => 'debit',
                'amount' => $amount,
                'description' => 'Withdrawal to ' . $wallet->bank_name . ' (' . $wallet->account_number . ')',
                'reference' => $reference,
                'status' => 'pending',
            ]);

            // Call gateway to initiate bank transfer
            $result = $this->gatewayService->initiateWithdrawal([
                'reference' => $reference,
                'amount' => $amount,
                'account_number' => $wallet->account_number,
                'account_name' => $wallet->account_name,
                'bank_code' => $wallet->bank_code,
                'bank_name' => $wallet->bank_name,
                'narration' => 'Cherishly wallet withdrawal',
            ]);

            // Gateway failed — reverse the deduction
            if (!$result['success']) {
                $wallet->increment('balance', $amount);
                $transaction->update(['status' => 'failed']);

                Log::error('Withdrawal gateway failed', [
                    'reference' => $reference,
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'error' => $result['message'],
                ]);

                return ['success' => false, 'message' => $result['message']];
            }

            return [
                'success' => true,
                'message' => 'Withdrawal initiated. You will receive funds within 24 hours.',
                'reference' => $reference,
                'amount' => $amount,
            ];
        });
    }

    /* ── Confirm withdrawal (called by webhook) ── */

    public function confirmWithdrawal(string $reference, string $status): bool
    {
        return DB::transaction(function () use ($reference, $status) {
            $transaction = \App\Models\Transaction::where('reference', $reference)
                ->where('type', 'debit')
                ->first();

            if (!$transaction)
                return false;

            if ($status === 'failed') {
                $transaction->wallet->increment('balance', $transaction->amount);
            }

            $transaction->update(['status' => $status]);

            Log::info('Withdrawal confirmed', [
                'reference' => $reference,
                'status' => $status,
            ]);

            return true;
        });
    }

    /* ── Get banks list ── */

    public function getBanks(): array
    {
        return $this->gatewayService->getBanks();
    }

    public function resolveAccount(string $accountNumber, string $bankCode): array
    {
        return $this->gatewayService->resolveBankAccount($accountNumber, $bankCode);
    }

    /* ── Get decrypted BVN ── */

    public function getBvn(User $user): ?string
    {
        $wallet = $this->getWallet($user);
        if (!$wallet->bvn_encrypted)
            return null;

        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($wallet->bvn_encrypted);
        } catch (\Exception $e) {
            Log::error('BVN decryption failed', ['user_id' => $user->id]);
            return null;
        }
    }

    /* ── Daily withdrawal limit ── */

    private function checkDailyWithdrawalLimit(Wallet $wallet, float $amount): array
    {
        $dailyLimit = (float) setting('daily_withdrawal_limit', 500000);

        $todayTotal = $wallet->transactions()
            ->where('type', 'debit')
            ->whereIn('status', ['pending', 'successful'])
            ->whereDate('created_at', today())
            ->sum('amount');

        if (($todayTotal + $amount) > $dailyLimit) {
            return [
                'success' => false,
                'message' => 'Daily withdrawal limit of ₦' . number_format($dailyLimit, 2) . ' exceeded.',
            ];
        }

        return ['success' => true];
    }
}
