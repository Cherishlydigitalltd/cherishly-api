<?php

namespace App\Jobs;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SettlePendingFunds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // Find all credit transactions that are now due for settlement
        $transactions = Transaction::where('type', 'credit')
            ->where('status', 'successful')
            ->whereNotNull('available_at')
            ->where('available_at', '<=', now())
            ->whereNull('settled_at')
            ->with('wallet')
            ->get();

        foreach ($transactions as $transaction) {
            DB::transaction(function () use ($transaction) {
                $wallet = $transaction->wallet;
                if (!$wallet)
                    return;

                $amount = (float) ($transaction->net_amount ?? $transaction->amount);

                // Move from pending to available
                $wallet->increment('available_balance', $amount);
                $wallet->decrement('pending_settlement', $amount);

                // Mark transaction as settled
                $transaction->update(['settled_at' => now()]);

                Log::info('Funds settled', [
                    'transaction_id' => $transaction->id,
                    'wallet_id' => $wallet->id,
                    'amount' => $amount,
                ]);
            });
        }

        Log::info('SettlePendingFunds completed', ['count' => $transactions->count()]);
    }
}