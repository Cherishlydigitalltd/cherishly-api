<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class FeeService
{
    public function getRate(User $user): float
    {
        // Per-user rate overrides global rate
        if ($user->transaction_fee_rate !== null) {
            return (float) $user->transaction_fee_rate;
        }

        $global = DB::table('settings')->where('key', 'transaction_fee_rate')->value('value');
        return (float) ($global ?? 2.5);
    }

    public function calculate(float $contributionAmount, User $owner): array
    {
        $rate = $this->getRate($owner);
        $bearer = $owner->fee_bearer ?? 'gifter';
        $feeAmount = round($contributionAmount * ($rate / 100), 2);

        if ($bearer === 'gifter') {
            // Gifter pays extra on top — owner gets full amount
            $grossAmount = $contributionAmount + $feeAmount; // what gifter pays
            $netAmount = $contributionAmount;               // what owner receives
        } else {
            // Owner pays — fee deducted from contribution
            $grossAmount = $contributionAmount;               // what gifter pays
            $netAmount = $contributionAmount - $feeAmount;  // what owner receives
        }

        return [
            'gross_amount' => $grossAmount,
            'net_amount' => $netAmount,
            'fee_amount' => $feeAmount,
            'fee_rate' => $rate,
            'fee_bearer' => $bearer,
            'charge_amount' => $grossAmount, // amount to send to Paystack
        ];
    }

    public function getWithdrawalRate(User $user): float
    {
        // Per-user rate overrides global
        if ($user->withdrawal_fee_rate !== null) {
            return (float) $user->withdrawal_fee_rate;
        }

        $global = DB::table('settings')->where('key', 'withdrawal_fee_rate')->value('value');
        return (float) ($global ?? 1.0);
    }

    public function calculateWithdrawalFee(float $amount, User $user): array
    {
        $rate = $this->getWithdrawalRate($user);
        $min = (float) (DB::table('settings')->where('key', 'withdrawal_fee_min')->value('value') ?? 50);
        $cap = (float) (DB::table('settings')->where('key', 'withdrawal_fee_cap')->value('value') ?? 2000);

        if ($rate === 0.0) {
            // Fee waived for this user
            return [
                'fee_rate' => 0,
                'fee_amount' => 0,
                'gross_amount' => $amount,
                'net_amount' => $amount,
            ];
        }

        $feeAmount = round($amount * ($rate / 100), 2);
        $feeAmount = max($min, min($cap, $feeAmount)); // apply min and cap

        return [
            'fee_rate' => $rate,
            'fee_amount' => $feeAmount,
            'gross_amount' => $amount,
            'net_amount' => round($amount - $feeAmount, 2),
        ];
    }
}