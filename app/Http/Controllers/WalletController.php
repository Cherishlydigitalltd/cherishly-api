<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Wallet\UpdateBankDetailsRequest;
use App\Http\Requests\Wallet\WithdrawRequest;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(
        private WalletService $walletService
    ) {}

    /**
     * GET /api/wallet
     * Get wallet balance and bank details
     */
    public function show(Request $request): JsonResponse
    {
        $summary = $this->walletService->getSummary($request->user());

        return ApiResponse::success('Wallet retrieved.', $summary);
    }

    /**
     * GET /api/wallet/transactions
     * Get transaction history
     */
    public function transactions(Request $request): JsonResponse
    {
        $filters = $request->only(['type', 'status']);

        $transactions = $this->walletService->getTransactions($request->user(), $filters);

        return ApiResponse::success('Transactions retrieved.', $transactions);
    }

    /**
     * PUT /api/wallet/bank-details
     * Add or update bank account for withdrawals
     */
    public function updateBankDetails(UpdateBankDetailsRequest $request): JsonResponse
    {
        $wallet = $this->walletService->updateBankDetails($request->user(), $request->validated());

        return ApiResponse::success('Bank details updated successfully.', [
            'bank_name'      => $wallet->bank_name,
            'account_number' => $wallet->account_number,
            'account_name'   => $wallet->account_name,
            'bank_code'      => $wallet->bank_code,
        ]);
    }

    /**
     * POST /api/wallet/withdraw
     * Initiate withdrawal
     */
    public function withdraw(WithdrawRequest $request): JsonResponse
    {
        $result = $this->walletService->withdraw($request->user(), $request->amount);

        if (!$result['success']) {
            return ApiResponse::error($result['message']);
        }

        return ApiResponse::success($result['message'], [
            'reference' => $result['reference'],
            'amount'    => $result['amount'],
        ]);
    }
}
