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
}