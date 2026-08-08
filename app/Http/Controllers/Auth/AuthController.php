<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResendOtpRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService
    ) {}

    /**
     * POST /api/auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());

        return ApiResponse::success(
            'Account created successfully. Please verify your email.',
            $result,
            201
        );
    }

    /**
     * POST /api/auth/verify-email
     */
    public function verifyEmail(VerifyOtpRequest $request): JsonResponse
    {
        $user   = \App\Models\User::where('email', $request->email)->firstOrFail();
        $result = $this->authService->verifyEmail($user, $request->otp);

        if (!$result['success']) {
            return ApiResponse::error($result['message']);
        }

        return ApiResponse::success('Email verified successfully.', [
            'token' => $result['token'],
            'user'  => $result['user'],
        ]);
    }

    /**
     * POST /api/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated());

        if (!$result['success']) {
            if (!empty($result['requires_verify'])) {
                return ApiResponse::error($result['message'], [
                    'requires_verify' => true,
                    'email'           => $result['email'],
                ], 403);
            }
            return ApiResponse::error($result['message'], null, 401);
        }

        return ApiResponse::success('Login successful.', [
            'token' => $result['token'],
            'user'  => $result['user'],
        ]);
    }

    /**
     * POST /api/auth/forgot-password
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $result = $this->authService->forgotPassword($request->email);

        return ApiResponse::success($result['message'], 
            isset($result['email']) ? ['email' => $result['email']] : null
        );
    }

    /**
     * POST /api/auth/verify-identity
     */
    public function verifyIdentity(VerifyOtpRequest $request): JsonResponse
    {
        $result = $this->authService->verifyIdentity($request->email, $request->otp);

        if (!$result['success']) {
            return ApiResponse::error($result['message']);
        }

        return ApiResponse::success('Identity verified.', [
            'reset_token' => $result['reset_token'],
        ]);
    }

    /**
     * POST /api/auth/reset-password
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $user   = $request->user();
        $result = $this->authService->resetPassword($user, $request->password);

        return ApiResponse::success($result['message']);
    }

    /**
     * POST /api/auth/resend-otp
     */
    public function resendOtp(ResendOtpRequest $request): JsonResponse
    {
        $result = $this->authService->resendOtp($request->email, $request->type);

        return ApiResponse::success($result['message']);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return ApiResponse::success('Logged out successfully.');
    }

    /**
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success('User retrieved.', [
            'user' => $request->user()->load('wallet'),
        ]);
    }
}
