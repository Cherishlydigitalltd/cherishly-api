<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Nightly check that every wallet's numbers add up, emailed to ALERT_EMAIL
 * when something doesn't. Read-only: it never changes a balance.
 *
 *  1. Balance = successful credits − (successful + pending) withdrawals.
 *  2. Balance = available to withdraw + still settling.
 *  3. Settling = credits whose next-day settlement hasn't run yet.
 *  4. No figure below zero.
 *  Plus withdrawals still pending after a day (payout result never arrived).
 *
 * Scheduled hourly overnight and runs once a day; --force runs it again.
 */
class CheckWallets extends Command
{
    protected $signature = 'wallets:check {--force : Run even if it already ran today} {--no-email : Only print the result}';

    protected $description = 'Check every wallet balance against its transactions';

    public function handle(): int
    {
        $ranKey = 'wallets-check-ran:' . now()->format('Y-m-d');
        if (!$this->option('force') && Cache::has($ranKey)) {
            $this->info('Already checked today.');
            return self::SUCCESS;
        }

        $problems = $this->walletProblems();
        $stuck = $this->stuckWithdrawals();

        Cache::put($ranKey, 1, now()->addDay());

        if (!$problems && !$stuck) {
            $msg = 'Wallet check: all ' . DB::table('wallets')->count() . ' wallets add up, no stuck withdrawals.';
            Log::info($msg);
            $this->info($msg);
            return self::SUCCESS;
        }

        $report = $this->report($problems, $stuck);
        Log::warning('Wallet check found problems', ['wallets' => count($problems), 'stuck_withdrawals' => count($stuck)]);
        $this->line($report);

        if (!$this->option('no-email') && ($to = config('app.alert_email'))) {
            $subject = '[Cherishly API] Wallet check: '
                . implode(', ', array_filter([
                    $problems ? count($problems) . ' wallet' . (count($problems) === 1 ? '' : 's') . ' to look at' : null,
                    $stuck ? count($stuck) . ' stuck withdrawal' . (count($stuck) === 1 ? '' : 's') : null,
                ]));
            try {
                Mail::raw($report, fn ($m) => $m->to($to)->subject($subject));
            } catch (Throwable $e) {
                Log::error('Wallet check email failed: ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    /** @return array<int, array{wallet: object, issues: string[]}> */
    private function walletProblems(): array
    {
        $rows = DB::select("
            SELECT w.id, w.user_id, w.balance, w.available_balance, w.pending_settlement,
                   u.first_name, u.last_name, u.email,
                   COALESCE(t.credits, 0) AS credits,
                   COALESCE(t.debits, 0) AS debits,
                   COALESCE(t.settling, 0) AS settling
            FROM wallets w
            LEFT JOIN users u ON u.id = w.user_id
            LEFT JOIN (
                SELECT wallet_id,
                    SUM(CASE WHEN type = 'credit' AND status = 'successful' THEN amount ELSE 0 END) AS credits,
                    SUM(CASE WHEN type = 'debit' AND status IN ('successful', 'pending') THEN amount ELSE 0 END) AS debits,
                    SUM(CASE WHEN type = 'credit' AND status = 'successful' AND available_at IS NOT NULL AND settled_at IS NULL
                             THEN COALESCE(net_amount, amount) ELSE 0 END) AS settling
                FROM transactions GROUP BY wallet_id
            ) t ON t.wallet_id = w.id
            ORDER BY w.id
        ");

        $off = fn ($a, $b) => abs((float) $a - (float) $b) >= 0.01;
        $out = [];

        foreach ($rows as $w) {
            $issues = [];
            $expected = (float) $w->credits - (float) $w->debits;

            if ($off($w->balance, $expected)) {
                $issues[] = 'Balance ' . self::naira($w->balance) . ' but its transactions add up to ' . self::naira($expected)
                    . ' (' . self::signed((float) $w->balance - $expected) . ').';
            }
            if ($off($w->balance, (float) $w->available_balance + (float) $w->pending_settlement)) {
                $gap = (float) $w->balance - (float) $w->available_balance - (float) $w->pending_settlement;
                $issues[] = 'Available ' . self::naira($w->available_balance) . ' + settling ' . self::naira($w->pending_settlement)
                    . ' doesn\'t equal the balance ' . self::naira($w->balance) . ' (' . self::signed($gap) . ' unaccounted'
                    . ($gap > 0 ? ', this money can\'t be withdrawn' : '') . ').';
            }
            if ($off($w->pending_settlement, $w->settling)) {
                $issues[] = 'Settling shows ' . self::naira($w->pending_settlement) . ' but unsettled payments add up to ' . self::naira($w->settling) . '.';
            }
            foreach (['balance' => 'Balance', 'available_balance' => 'Available', 'pending_settlement' => 'Settling'] as $col => $label) {
                if ((float) $w->{$col} < -0.005) {
                    $issues[] = "{$label} is below zero: " . self::naira($w->{$col}) . '.';
                }
            }

            if ($issues) {
                $out[] = ['wallet' => $w, 'issues' => $issues];
            }
        }

        return $out;
    }

    private function stuckWithdrawals()
    {
        return Transaction::with('user:id,first_name,last_name,email')
            ->where('type', 'debit')
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subDay())
            ->orderBy('created_at')
            ->get()
            ->all();
    }

    private function report(array $problems, array $stuck): string
    {
        $admin = rtrim((string) config('app.admin_url', 'https://admin.cherishlyng.com'), '/');
        $lines = ['Nightly wallet check, ' . now()->toDayDateTimeString() . ' (UTC).', ''];

        if ($problems) {
            $lines[] = count($problems) . ' wallet' . (count($problems) === 1 ? '' : 's') . ' where the numbers don\'t add up:';
            $lines[] = '';
            foreach ($problems as $p) {
                $w = $p['wallet'];
                $who = trim("{$w->first_name} {$w->last_name}") ?: 'Deleted user';
                $lines[] = "Wallet #{$w->id}, {$who}" . ($w->email ? " <{$w->email}>" : '');
                foreach ($p['issues'] as $issue) {
                    $lines[] = "  - {$issue}";
                }
                $lines[] = "  {$admin}/wallets/{$w->id}";
                $lines[] = '';
            }
        }

        if ($stuck) {
            $lines[] = count($stuck) . ' withdrawal' . (count($stuck) === 1 ? '' : 's') . ' still pending after a day (check them in Paystack):';
            $lines[] = '';
            foreach ($stuck as $t) {
                $who = $t->user ? trim("{$t->user->first_name} {$t->user->last_name}") : 'Deleted user';
                $lines[] = "  - {$t->reference}: " . self::naira($t->amount) . " for {$who}, since " . $t->created_at->format('j M Y H:i')
                    . "  {$admin}/transactions/{$t->id}";
            }
            $lines[] = '';
        }

        $lines[] = 'Nothing was changed. This check only reads the numbers.';
        $lines[] = 'You\'ll get this email each night until the items above are sorted.';

        return implode("\n", $lines);
    }

    private static function naira($amount): string
    {
        $amount = (float) $amount;
        return ($amount < 0 ? '−' : '') . '₦' . number_format(abs($amount), 2);
    }

    private static function signed(float $amount): string
    {
        return ($amount >= 0 ? '+' : '−') . self::naira(abs($amount));
    }
}
