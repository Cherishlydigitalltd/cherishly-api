<?php

namespace App\Services;

use App\Models\User;
use App\Services\BvnService;
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
            'available_balance' => $wallet->available_balance,
            'pending_settlement' => $wallet->pending_amount,
            'total_received' => $wallet->total_received,
            'total_withdrawn' => $wallet->total_withdrawn,
            'bank_details' => [
                'bank_name' => $wallet->bank_name,
                'account_number' => $wallet->account_number,
                'account_name' => $wallet->account_name,
                'bank_code' => $wallet->bank_code,
                'bvn_verified' => (bool) $wallet->bvn_verified,
                'bvn_last4' => $wallet->bvnLast4(),
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

        // 3. BVN (KYC): required the first time, and whenever a different BVN is
        //    given. Checked with Scapa: it must be valid, not watchlisted, and in
        //    this user's name. The BVN is stored encrypted.
        $wallet = $this->getWallet($user);
        $bvn = $data['bvn'] ?? null;
        $kyc = [];

        if (!$bvn && !$wallet->bvn_verified) {
            return ['success' => false, 'message' => 'Please enter your BVN. We need it to verify your identity before paying money out.'];
        }

        if ($bvn && !($wallet->bvn_verified && $wallet->bvn() === $bvn)) {
            $hash = BvnService::hash($bvn);
            $usedElsewhere = \App\Models\Wallet::where('bvn_hash', $hash)
                ->where('user_id', '!=', $user->id)->where('bvn_verified', true)->exists();
            if ($usedElsewhere) {
                Log::warning('BVN already used by another account', ['user_id' => $user->id]);
                return ['success' => false, 'message' => 'This BVN is already linked to another Cherishly account. Please contact support@cherishlyng.com.'];
            }

            $check = app(BvnService::class)->verify($user, $bvn);
            if (!$check['success']) {
                return ['success' => false, 'message' => $check['message']];
            }

            $id = $check['identity'];
            $kyc = [
                'bvn_verified' => true,
                'bvn_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString($bvn),
                'bvn_hash' => $hash,
                'bvn_full_name' => $id['full_name'],
                'bvn_date_of_birth' => $id['date_of_birth'],
                'bvn_address' => $id['address'],
                'bvn_state' => $id['state'],
                'bvn_lga' => $id['lga'],
                'bvn_reference' => $id['reference'],
                'bvn_verified_at' => now(),
            ];
        }

        $wallet->update([
            'bank_name' => $data['bank_name'],
            'account_number' => $data['account_number'],
            'account_name' => $resolvedName,
            'bank_code' => $data['bank_code'],
        ] + $kyc);

        return [
            'success' => true,
            'wallet' => $wallet->fresh(),
        ];
    }

    /* ── Withdraw ── */

    public function withdraw1(User $user, float $amount): array
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

        // Calculate fee
        $feeService = app(\App\Services\FeeService::class);
        $fee = $feeService->calculateWithdrawalFee($amount, $user);

        // Validate sufficient balance (check gross amount)
        if ($wallet->balance < $fee['gross_amount']) {
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

        return DB::transaction(function () use ($wallet, $user, $amount, $fee) {
            $reference = 'WD-' . strtoupper(uniqid());

            // Deduct full gross amount from balance
            $wallet->decrement('balance', $fee['gross_amount']);

            // Create pending transaction with fee breakdown
            $transaction = $wallet->transactions()->create([
                'user_id' => $user->id,
                'type' => 'debit',
                'amount' => $fee['gross_amount'],
                'gross_amount' => $fee['gross_amount'],
                'net_amount' => $fee['net_amount'],
                'fee_amount' => $fee['fee_amount'],
                'fee_rate' => $fee['fee_rate'],
                'description' => 'Withdrawal to ' . $wallet->bank_name . ' (' . $wallet->account_number . ')'
                    . ($fee['fee_amount'] > 0 ? ' — fee: ₦' . number_format($fee['fee_amount'], 2) : ''),
                'reference' => $reference,
                'status' => 'pending',
            ]);

            // Send net amount to gateway (what user actually receives)
            $result = $this->gatewayService->initiateWithdrawal([
                'reference' => $reference,
                'amount' => $fee['net_amount'], // ← send net amount
                'account_number' => $wallet->account_number,
                'account_name' => $wallet->account_name,
                'bank_code' => $wallet->bank_code,
                'bank_name' => $wallet->bank_name,
                'narration' => 'Cherishly wallet withdrawal',
            ]);

            // Gateway failed — reverse the deduction
            if (!$result['success']) {
                $wallet->increment('balance', $fee['gross_amount']);
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
                'amount' => $fee['gross_amount'],
                'fee_amount' => $fee['fee_amount'],
                'net_amount' => $fee['net_amount'],
                'fee_waived' => $fee['fee_rate'] === 0,
            ];
        });
    }

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

        // Calculate fee
        $feeService = app(\App\Services\FeeService::class);
        $fee = $feeService->calculateWithdrawalFee($amount, $user);

        // Validate sufficient AVAILABLE balance (T+1 check)
        if ($wallet->available_balance < $fee['gross_amount']) {
            $pending = (float) $wallet->balance - (float) $wallet->available_balance;
            $availableDate = now()->addDay()->format('M j, Y');

            if ($pending > 0) {
                return [
                    'success' => false,
                    'message' => "You have ₦" . number_format($pending, 2) . " pending settlement. It will be available for withdrawal by {$availableDate}.",
                ];
            }

            return ['success' => false, 'message' => 'Insufficient available balance.'];
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

        // KYC: payouts only to a BVN-verified account holder.
        if (!$wallet->bvn_verified) {
            return [
                'success' => false,
                'message' => 'Please verify your BVN before withdrawing. Go to your payout account and add your BVN.',
                'needs_bvn' => true,
            ];
        }

        // Step 1: take the money out of the wallet and record the pending
        // withdrawal, then COMMIT, before talking to the payout provider.
        // If the request to the provider then hangs or PHP is killed
        // mid-call, the deduction is already saved, so the money can't be
        // both sent and left in the wallet.
        $debit = DB::transaction(function () use ($wallet, $user, $amount, $fee) {
            // Lock the wallet row so two withdrawals sent at the same moment
            // run one after the other. Without this, both could pass the
            // balance check above before either deducted, paying out the
            // same money twice. The checks are repeated on the locked,
            // freshly-read row.
            $wallet = Wallet::whereKey($wallet->id)->lockForUpdate()->first();

            if ((float) $wallet->available_balance < $fee['gross_amount']) {
                return ['success' => false, 'message' => 'Insufficient available balance.'];
            }

            $limitCheck = $this->checkDailyWithdrawalLimit($wallet, $amount);
            if (!$limitCheck['success']) {
                return $limitCheck;
            }

            $reference = 'WD-' . strtoupper(uniqid());

            // Deduct from both balance and available_balance
            $wallet->decrement('balance', $fee['gross_amount']);
            $wallet->decrement('available_balance', $fee['gross_amount']);

            // Create pending transaction with fee breakdown
            $transaction = $wallet->transactions()->create([
                'user_id' => $user->id,
                'type' => 'debit',
                'amount' => $fee['gross_amount'],
                'gross_amount' => $fee['gross_amount'],
                'net_amount' => $fee['net_amount'],
                'fee_amount' => $fee['fee_amount'],
                'fee_rate' => $fee['fee_rate'],
                'description' => 'Withdrawal to ' . $wallet->bank_name . ' (' . $wallet->account_number . ')'
                    . ($fee['fee_amount'] > 0 ? ' — fee: ₦' . number_format($fee['fee_amount'], 2) : ''),
                'reference' => $reference,
                'status' => 'pending',
            ]);

            return ['success' => true, 'transaction' => $transaction, 'wallet' => $wallet];
        });

        if (!$debit['success']) {
            return $debit;
        }

        /** @var \App\Models\Transaction $transaction */
        $transaction = $debit['transaction'];
        $wallet = $debit['wallet'];
        $reference = $transaction->reference;

        // Step 2: ask the provider to send the net amount.
        $result = $this->gatewayService->initiateWithdrawal([
            'reference' => $reference,
            'amount' => $fee['net_amount'],
            'account_number' => $wallet->account_number,
            'account_name' => $wallet->account_name,
            'bank_code' => $wallet->bank_code,
            'bank_name' => $wallet->bank_name,
            'narration' => 'Cherishly wallet withdrawal',
        ]);

        $done = [
            'success' => true,
            'message' => 'Withdrawal initiated. You will receive funds within 24 hours.',
            'reference' => $reference,
            'amount' => $fee['gross_amount'],
            'fee_amount' => $fee['fee_amount'],
            'net_amount' => $fee['net_amount'],
            'fee_waived' => $fee['fee_rate'] === 0,
        ];

        if ($result['success']) {
            return $done;
        }

        // We don't know whether the provider sent the money (timeout,
        // provider error, duplicate reference). Refunding now could pay the
        // customer twice, so leave it pending: the provider's webhook will
        // mark it successful or failed (and refund). The admin Finance page
        // flags withdrawals pending for more than a day.
        if (!empty($result['uncertain'])) {
            Log::warning('Withdrawal outcome unknown; left pending for the webhook', [
                'reference' => $reference,
                'user_id' => $user->id,
                'amount' => $amount,
                'error' => $result['message'],
            ]);

            return ['message' => 'Your withdrawal is being processed. If it doesn’t go through, the money will return to your wallet.'] + $done;
        }

        // The provider clearly refused it, so nothing was sent: put the money back.
        $this->reverseWithdrawal($transaction);

        Log::error('Withdrawal gateway failed', [
            'reference' => $reference,
            'user_id' => $user->id,
            'amount' => $amount,
            'error' => $result['message'],
        ]);

        return ['success' => false, 'message' => $result['message']];
    }

    /**
     * Refund a pending withdrawal the provider refused. Locked and only
     * acts while still pending, so a later "failed" webhook for the same
     * reference can't refund it a second time.
     */
    private function reverseWithdrawal(\App\Models\Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $tx = \App\Models\Transaction::whereKey($transaction->id)->lockForUpdate()->first();
            if (!$tx || $tx->status !== 'pending') {
                return;
            }
            $wallet = Wallet::whereKey($tx->wallet_id)->lockForUpdate()->first();
            $wallet->increment('balance', $tx->amount);
            $wallet->increment('available_balance', $tx->amount);
            $tx->update(['status' => 'failed']);
        });
    }

    /* ── Confirm withdrawal (called by webhook) ── */

    public function confirmWithdrawal(string $reference, string $status): bool
    {
        return DB::transaction(function () use ($reference, $status) {
            $transaction = \App\Models\Transaction::where('reference', $reference)
                ->where('type', 'debit')
                ->lockForUpdate()
                ->first();

            if (!$transaction)
                return false;

            // Gateways resend webhooks. Only a pending withdrawal can change;
            // otherwise a repeated "failed" would refund the money twice.
            if ($transaction->status !== 'pending') {
                Log::info('Withdrawal webhook ignored: already settled', [
                    'reference' => $reference,
                    'status' => $transaction->status,
                    'incoming' => $status,
                ]);
                return true;
            }

            if ($status === 'failed') {
                // Reverse both balance and available_balance
                $transaction->wallet->increment('balance', $transaction->amount);
                $transaction->wallet->increment('available_balance', $transaction->amount);
            }

            if ($status === 'successful') {
                // Track total withdrawn
                $transaction->wallet->increment('total_withdrawn', $transaction->amount);
            }

            $transaction->update(['status' => $status]);

            $this->notifyWithdrawal($transaction, $status);

            Log::info('Withdrawal confirmed', [
                'reference' => $reference,
                'status' => $status,
            ]);

            return true;
        });
    }

    private function notifyWithdrawal(\App\Models\Transaction $transaction, string $status): void
    {
        $user = $transaction->wallet->user ?? null;
        if (!$user || !in_array($status, ['successful', 'failed'], true)) {
            return;
        }

        $wallet = $transaction->wallet;
        $net = $transaction->net_amount ?? $transaction->amount;
        $bank = trim(($wallet->bank_name ?? 'your bank') . ($wallet->account_number ? ' (' . substr($wallet->account_number, -4) . ')' : ''));

        app(NotificationService::class)->create(
            $user,
            'withdrawal',
            $status === 'successful' ? 'Withdrawal successful' : 'Withdrawal failed',
            $status === 'successful'
                ? '₦' . number_format($net, 2) . " has been sent to {$bank}."
                : '₦' . number_format($transaction->amount, 2) . " couldn't be sent to {$bank}. It's back in your wallet.",
            '/dashboard/wallet',
            $status === 'successful' ? '💸' : '⚠️'
        );
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
