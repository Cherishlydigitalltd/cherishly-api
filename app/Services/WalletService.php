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
            ],
        ];
    }

    /* ── Update bank details ── */

    public function updateBankDetails(User $user, array $data): array
    {
        // Resolve account name from gateway
        $resolved = $this->gatewayService->resolveBankAccount(
            $data['account_number'],
            $data['bank_code']
        );

        if (!$resolved['success']) {
            return [
                'success' => false,
                'message' => $resolved['message'],
            ];
        }

        $wallet = $this->getWallet($user);
        $wallet->update([
            'bank_name' => $data['bank_name'],
            'account_number' => $data['account_number'],
            'account_name' => $resolved['account_name'],
            'bank_code' => $data['bank_code'],
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
            return [
                'success' => false,
                'message' => 'Insufficient balance.',
            ];
        }

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

                return [
                    'success' => false,
                    'message' => $result['message'],
                ];
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
                // Refund the balance
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
}
