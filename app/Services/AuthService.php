<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AuthService
{
    public function __construct(
        private OtpService $otpService
    ) {}

    /* ── Register ── */

    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'first_name' => $data['first_name'],
                'last_name'  => $data['last_name'],
                'email'      => $data['email'],
                'password'   => $data['password'], // hashed via cast
            ]);

            // Create wallet for user
            Wallet::create([
                'user_id'        => $user->id,
                'balance'        => 0,
                'total_received' => 0,
            ]);

            // Send email verification OTP
            $this->otpService->send($user, 'email_verification');

            return [
                'user' => $this->formatUser($user),
            ];
        });
    }

    /* ── Verify Email ── */

    public function verifyEmail(User $user, string $otp): array
    {
        $result = $this->otpService->verify($user, $otp, 'email_verification');

        if (!$result['success']) {
            return $result;
        }

        $user->update([
            'is_email_verified' => true,
            'email_verified_at' => now(),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return [
            'success' => true,
            'message' => 'Email verified successfully.',
            'token'   => $token,
            'user'    => $this->formatUser($user),
        ];
    }

    /* ── Login ── */

    public function login(array $data): array
    {
        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return ['success' => false, 'message' => 'Invalid email or password.'];
        }

        if (!$user->is_email_verified) {
            // Resend OTP
            $this->otpService->send($user, 'email_verification');
            return [
                'success'          => false,
                'message'          => 'Email not verified. A new OTP has been sent.',
                'requires_verify'  => true,
                'email'            => $user->email,
            ];
        }

        if (!$user->is_active) {
            return ['success' => false, 'message' => 'Your account has been deactivated. Please contact support.'];
        }

        // Revoke old tokens
        $user->tokens()->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

        return [
            'success' => true,
            'token'   => $token,
            'user'    => $this->formatUser($user),
        ];
    }

    /* ── Forgot Password ── */

    public function forgotPassword(string $email): array
    {
        $user = User::where('email', $email)->first();

        // Always return success to prevent email enumeration
        if (!$user) {
            return ['success' => true, 'message' => 'If this email exists, a reset OTP has been sent.'];
        }

        $this->otpService->send($user, 'password_reset');

        return [
            'success' => true,
            'message' => 'If this email exists, a reset OTP has been sent.',
            'email'   => $user->email,
        ];
    }

    /* ── Verify Identity (password reset OTP) ── */

    public function verifyIdentity(string $email, string $otp): array
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            return ['success' => false, 'message' => 'Invalid request.'];
        }

        $result = $this->otpService->verify($user, $otp, 'password_reset');

        if (!$result['success']) {
            return $result;
        }

        // Generate a short-lived reset token
        $resetToken = $user->createToken('password_reset', ['password-reset'], now()->addMinutes(15))->plainTextToken;

        return [
            'success'      => true,
            'message'      => 'Identity verified.',
            'reset_token'  => $resetToken,
        ];
    }

    /* ── Reset Password ── */

    public function resetPassword(User $user, string $password): array
    {
        $user->update(['password' => $password]);

        // Revoke all tokens including reset token
        $user->tokens()->delete();

        return ['success' => true, 'message' => 'Password reset successfully. Please login.'];
    }

    /* ── Logout ── */

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    /* ── Resend OTP ── */

    public function resendOtp(string $email, string $type): array
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            return ['success' => true, 'message' => 'If this email exists, an OTP has been sent.'];
        }

        $this->otpService->resend($user, $type);

        return ['success' => true, 'message' => 'OTP sent successfully.'];
    }

    /* ── Format user for response ── */

    private function formatUser(User $user): array
    {
        return [
            'id'                 => $user->id,
            'first_name'         => $user->first_name,
            'last_name'          => $user->last_name,
            'full_name'          => $user->full_name,
            'email'              => $user->email,
            'phone'              => $user->phone,
            'avatar'             => $user->avatar,
            'is_email_verified'  => $user->is_email_verified,
            'is_phone_verified'  => $user->is_phone_verified,
            'created_at'         => $user->created_at,
        ];
    }
}
