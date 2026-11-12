<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\MonetaryContribution;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only view of customer money: wallet balances, every credit and
 * debit, and withdrawals. Nothing here moves money.
 */
class AdminFinanceController extends Controller
{
    private const USER_FIELDS = 'user:id,first_name,last_name,email,phone,is_active';

    /** GET /admin/finance/summary */
    public function summary(): JsonResponse
    {
        $wallets = Wallet::query()->selectRaw('
            COUNT(*) AS wallets,
            COALESCE(SUM(balance), 0) AS balance,
            COALESCE(SUM(available_balance), 0) AS available_balance,
            COALESCE(SUM(pending_settlement), 0) AS pending_settlement,
            COALESCE(SUM(total_received), 0) AS total_received,
            COALESCE(SUM(total_withdrawn), 0) AS total_withdrawn
        ')->first();

        $withdrawals = fn () => Transaction::where('type', 'debit');

        $sum = fn (Builder $q) => [
            'count' => (clone $q)->count(),
            'amount' => (float) (clone $q)->sum('amount'),
        ];

        return ApiResponse::success('Finance summary.', [
            'wallets' => [
                'count' => (int) $wallets->wallets,
                'balance' => (float) $wallets->balance,
                'available_balance' => (float) $wallets->available_balance,
                'pending_settlement' => (float) $wallets->pending_settlement,
                'total_received' => (float) $wallets->total_received,
                'total_withdrawn' => (float) $wallets->total_withdrawn,
            ],
            'withdrawals' => [
                'pending' => $sum($withdrawals()->where('status', 'pending')),
                'successful' => $sum($withdrawals()->where('status', 'successful')),
                'failed_30d' => $sum($withdrawals()->where('status', 'failed')->where('created_at', '>=', now()->subDays(30))),
            ],
            'credits' => [
                'today' => $sum(Transaction::where('type', 'credit')->where('status', 'successful')->where('created_at', '>=', today())),
                'last_30d' => $sum(Transaction::where('type', 'credit')->where('status', 'successful')->where('created_at', '>=', now()->subDays(30))),
            ],
            'fees' => [
                'withdrawal_fees' => (float) Transaction::where('type', 'debit')->where('status', 'successful')->sum('fee_amount'),
                'contribution_fees' => (float) Transaction::where('type', 'credit')->where('status', 'successful')->sum('fee_amount'),
            ],
            // Withdrawals stuck in "pending" for more than a day usually
            // mean the payout webhook never arrived and need checking.
            'stuck_withdrawals' => Transaction::where('type', 'debit')
                ->where('status', 'pending')
                ->where('created_at', '<', now()->subDay())
                ->count(),
        ]);
    }

    /** GET /admin/wallets?search=&sort=balance|available_balance|total_received|recent&has_balance=1 */
    public function wallets(Request $request): JsonResponse
    {
        $query = Wallet::with(self::USER_FIELDS);

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', fn ($u) => $u
                    ->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%"))
                  ->orWhere('account_number', 'ilike', "%{$search}%")
                  ->orWhere('account_name', 'ilike', "%{$search}%");
            });
        }

        if ($request->boolean('has_balance')) {
            $query->where('balance', '>', 0);
        }
        if ($request->filled('bvn')) {
            $query->where('bvn_verified', $request->query('bvn') === 'verified');
        }

        $sort = $request->query('sort', 'balance');
        if (in_array($sort, ['balance', 'available_balance', 'pending_settlement', 'total_received', 'total_withdrawn'], true)) {
            $query->orderByDesc($sort)->orderByDesc('id');
        } else {
            $query->latest('updated_at');
        }

        return ApiResponse::success('Wallets retrieved.', $query->paginate(25));
    }

    /** GET /admin/wallets/{wallet} */
    public function wallet(Wallet $wallet): JsonResponse
    {
        $wallet->load(self::USER_FIELDS);
        // Admins see the last 4 digits only, never the full BVN.
        $wallet->setAttribute('bvn_last4', $wallet->bvnLast4());

        $totals = $wallet->transactions()
            ->selectRaw("type, status, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount")
            ->groupBy('type', 'status')
            ->get();

        return ApiResponse::success('Wallet retrieved.', [
            'wallet' => $wallet,
            'totals' => $totals,
        ]);
    }

    /**
     * GET /admin/transactions
     *   type=credit|debit, status=pending|successful|failed,
     *   kind=withdrawal|gift|cash|vendor, wallet_id, user_id,
     *   search (reference, description, user), from, to (Y-m-d)
     */
    public function transactions(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', 'in:credit,debit'],
            'status' => ['nullable', 'in:pending,successful,failed'],
            'kind' => ['nullable', 'in:withdrawal,gift,cash,vendor'],
            'wallet_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = Transaction::with(['wallet:id,user_id,bank_name,account_number,account_name', self::USER_FIELDS])
            ->latest()->orderByDesc('id');

        foreach (['type', 'status', 'wallet_id', 'user_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }

        match ($request->query('kind')) {
            'withdrawal' => $query->where('reference', 'like', 'WD-%'),
            'gift' => $query->where('reference', 'like', 'CHR\_GIFT\_%'),
            'cash' => $query->where('reference', 'like', 'CHR\_MON\_%'),
            'vendor' => $query->where('reference', 'like', 'VPO-%'),
            default => null,
        };

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->date('from')->startOfDay());
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->date('to')->endOfDay());
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%")
                  ->orWhereHas('user', fn ($u) => $u
                      ->where('email', 'ilike', "%{$search}%")
                      ->orWhere('first_name', 'ilike', "%{$search}%")
                      ->orWhere('last_name', 'ilike', "%{$search}%"));
            });
        }

        // Totals for the current filter, so the page can show "₦X across N".
        $totals = (clone $query)->reorder()->selectRaw("
            COUNT(*) AS count,
            COALESCE(SUM(amount), 0) AS amount,
            COALESCE(SUM(CASE WHEN type = 'credit' THEN amount END), 0) AS credits,
            COALESCE(SUM(CASE WHEN type = 'debit' THEN amount END), 0) AS debits
        ")->first();

        $page = $query->paginate(30);

        return ApiResponse::success('Transactions retrieved.', [
            'page' => $page,
            'totals' => [
                'count' => (int) $totals->count,
                'amount' => (float) $totals->amount,
                'credits' => (float) $totals->credits,
                'debits' => (float) $totals->debits,
            ],
        ]);
    }

    /** GET /admin/transactions/{transaction} */
    public function transaction(Transaction $transaction): JsonResponse
    {
        $transaction->load(['wallet', self::USER_FIELDS]);

        // The payment behind a credit, if there is one (who paid, how).
        $payment = null;
        if ($transaction->reference) {
            $payment = Contribution::with('gift:id,name')
                ->where('payment_reference', $transaction->reference)->first()
                ?? MonetaryContribution::with('monetaryGift:id,title')
                    ->where('payment_reference', $transaction->reference)->first();
            // Never send a payer's BVN or raw gateway data to the dashboard.
            $payment?->makeHidden(['bvn', 'payment_meta']);
        }

        return ApiResponse::success('Transaction retrieved.', [
            'transaction' => $transaction,
            'payment' => $payment,
        ]);
    }
}
