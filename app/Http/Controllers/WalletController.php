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
    ) {
    }

    /**
     * GET /api/wallet
     */
    public function show(Request $request): JsonResponse
    {
        $summary = $this->walletService->getSummary($request->user());
        return ApiResponse::success('Wallet retrieved.', $summary);
    }

    /**
     * GET /api/wallet/transactions
     */
    public function transactions(Request $request): JsonResponse
    {
        $filters = $request->only(['type', 'status']);
        $transactions = $this->walletService->getTransactions($request->user(), $filters);
        return ApiResponse::success('Transactions retrieved.', $transactions);
    }

    /**
     * PUT /api/wallet/bank-details
     */
    public function updateBankDetails(UpdateBankDetailsRequest $request): JsonResponse
    {
        $result = $this->walletService->updateBankDetails($request->user(), $request->validated());

        if (!$result['success']) {
            return ApiResponse::error($result['message']);
        }

        $wallet = $result['wallet'];

        return ApiResponse::success('Bank details updated successfully.', [
            'bank_name' => $wallet->bank_name,
            'account_number' => $wallet->account_number,
            'account_name' => $wallet->account_name,
            'bank_code' => $wallet->bank_code,
        ]);
    }

    /**
     * POST /api/wallet/withdraw
     */
    public function withdraw(WithdrawRequest $request): JsonResponse
    {
        $result = $this->walletService->withdraw($request->user(), $request->amount);

        if (!$result['success']) {
            return ApiResponse::error($result['message']);
        }

        return ApiResponse::success($result['message'], [
            'reference' => $result['reference'],
            'amount' => $result['amount'],
        ]);
    }

    /**
     * GET /api/wallet/banks
     * Get list of supported banks from gateway
     */
    public function banks(Request $request): JsonResponse
    {
        $banks = $this->walletService->getBanks();
        return ApiResponse::success('Banks retrieved.', $banks);
    }

    public function resolveAccount(Request $request): JsonResponse
    {
        $request->validate([
            'account_number' => ['required', 'string', 'size:10'],
            'bank_code' => ['required', 'string'],
        ]);

        $result = $this->walletService->resolveAccount(
            $request->account_number,
            $request->bank_code
        );

        if (!$result['success']) {
            return ApiResponse::error($result['message'], null, 422);
        }

        return ApiResponse::success('Account resolved.', [
            'account_name' => $result['account_name'],
        ]);
    }
}
