<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class WalletService
{
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

        $totalCredits = $wallet->transactions()
            ->where('type', 'credit')
            ->where('status', 'successful')
            ->sum('amount');

        $totalDebits = $wallet->transactions()
            ->where('type', 'debit')
            ->where('status', 'successful')
            ->sum('amount');

        return [
            'balance' => $wallet->balance,
            'total_received' => $wallet->total_received,
            'total_credited' => $totalCredits,
            'total_debited' => $totalDebits,
            'bank_details' => [
                'bank_name' => $wallet->bank_name,
                'account_number' => $wallet->account_number,
                'account_name' => $wallet->account_name,
                'bank_code' => $wallet->bank_code,
            ],
        ];
    }

    /* ── Update bank details ── */

    public function updateBankDetails(User $user, array $data): Wallet
    {
        $wallet = $this->getWallet($user);

        $wallet->update([
            'bank_name' => $data['bank_name'],
            'account_number' => $data['account_number'],
            'account_name' => $data['account_name'],
            'bank_code' => $data['bank_code'],
        ]);

        return $wallet->fresh();
    }

    /* ── Withdraw ── */

    public function withdraw(User $user, float $amount): array
    {
        $wallet = $this->getWallet($user);

        $minWithdrawal = (float) setting('min_withdrawal', 1000);

        if ($amount < $minWithdrawal) {
            return [
                'success' => false,
                'message' => "Minimum withdrawal amount is ₦" . number_format($minWithdrawal, 2),
            ];
        }

        if ($wallet->balance < $amount) {
            return [
                'success' => false,
                'message' => 'Insufficient balance.',
            ];
        }

        if (!$wallet->account_number) {
            return [
                'success' => false,
                'message' => 'Please add your bank details before withdrawing.',
            ];
        }

        return DB::transaction(function () use ($wallet, $amount) {
            $reference = 'WD-' . strtoupper(uniqid());

            $wallet->decrement('balance', $amount);

            $wallet->transactions()->create([
                'user_id' => $wallet->user_id,
                'type' => 'debit',
                'amount' => $amount,
                'description' => 'Withdrawal to ' . $wallet->bank_name,
                'reference' => $reference,
                'status' => 'pending',
            ]);

            // TODO: Call .NET Core gateway to initiate bank transfer
            // GatewayService::withdraw($wallet, $amount, $reference);

            return [
                'success' => true,
                'message' => 'Withdrawal initiated. You will receive funds within 24 hours.',
                'reference' => $reference,
                'amount' => $amount,
            ];
        });
    }
}
