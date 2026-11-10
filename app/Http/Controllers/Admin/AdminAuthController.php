<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Mail\AdminLoginCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AdminAuthController extends Controller
{
    /** Codes live this long, and allow this many wrong guesses / resends. */
    private const CODE_MINUTES = 10;
    private const MAX_ATTEMPTS = 5;
    private const MAX_SENDS = 3;

    /**
     * Step 1: email + password. With two-step sign-in on (ADMIN_TWO_FACTOR),
     * this emails a 6-digit code and returns a `challenge` instead of a
     * token; step 2 is verifyCode().
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $admin = Admin::where('email', strtolower($request->email))->first();

        if (!$admin || !Hash::check($request->password, $admin->password)) {
            return ApiResponse::error('Invalid credentials.', null, 401);
        }

        if (!$admin->is_active) {
            return ApiResponse::error('Your account has been suspended.', null, 403);
        }

        if (!config('auth.admin_two_factor')) {
            return $this->issueToken($admin);
        }

        $challenge = Str::random(48);
        if (!$this->sendCode($admin, $challenge, sends: 1, request: $request)) {
            return ApiResponse::error('We couldn’t send your sign-in code. Please try again shortly.', null, 503);
        }

        return ApiResponse::success('We sent a sign-in code to your email.', [
            'two_factor' => true,
            'challenge' => $challenge,
            'email' => $this->maskEmail($admin->email),
            'expires_in_minutes' => self::CODE_MINUTES,
        ]);
    }

    /** Step 2: the emailed code. */
    public function verifyCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:10'],
        ]);

        $key = $this->challengeKey($data['challenge']);
        $entry = Cache::get($key);

        if (!$entry || now()->timestamp > $entry['expires_at']) {
            Cache::forget($key);
            return ApiResponse::error('This code has expired. Please sign in again.', null, 422);
        }

        if (!Hash::check(preg_replace('/\D/', '', $data['code']), $entry['code'])) {
            $entry['attempts']++;
            if ($entry['attempts'] >= self::MAX_ATTEMPTS) {
                Cache::forget($key);
                return ApiResponse::error('Too many wrong codes. Please sign in again.', null, 422);
            }
            Cache::put($key, $entry, $entry['expires_at'] - now()->timestamp);
            $left = self::MAX_ATTEMPTS - $entry['attempts'];
            return ApiResponse::error("That code isn’t right. {$left} " . ($left === 1 ? 'try' : 'tries') . ' left.', null, 422);
        }

        // One use only.
        Cache::forget($key);

        $admin = Admin::find($entry['admin_id']);
        if (!$admin || !$admin->is_active) {
            return ApiResponse::error('Your account has been suspended.', null, 403);
        }

        return $this->issueToken($admin);
    }

    /** Send a fresh code for the same sign-in attempt. */
    public function resendCode(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string', 'max:100']]);

        $entry = Cache::get($this->challengeKey($data['challenge']));
        if (!$entry || now()->timestamp > $entry['expires_at']) {
            return ApiResponse::error('This sign-in has expired. Please sign in again.', null, 422);
        }
        if ($entry['sends'] >= self::MAX_SENDS) {
            return ApiResponse::error('No more codes for this sign-in. Please sign in again.', null, 429);
        }

        $admin = Admin::find($entry['admin_id']);
        if (!$admin || !$admin->is_active) {
            return ApiResponse::error('Your account has been suspended.', null, 403);
        }

        if (!$this->sendCode($admin, $data['challenge'], $entry['sends'] + 1, $request)) {
            return ApiResponse::error('We couldn’t send your sign-in code. Please try again shortly.', null, 503);
        }

        return ApiResponse::success('We sent a new code.');
    }

    private function issueToken(Admin $admin): JsonResponse
    {
        $admin->update(['last_login_at' => now()]);
        $token = $admin->createToken('admin-token', ['*'], now()->addHours(config('sanctum.admin_token_hours')))->plainTextToken;

        return ApiResponse::success('Login successful.', [
            'token' => $token,
            'admin' => $admin,
        ]);
    }

    /** Store a new hashed code for this challenge (fresh attempts and expiry) and email it. */
    private function sendCode(Admin $admin, string $challenge, int $sends, Request $request): bool
    {
        $code = (string) random_int(100000, 999999);

        try {
            Mail::to($admin->email)->send(new AdminLoginCode($admin, $code, (string) $request->ip()));
        } catch (\Throwable $e) {
            Log::error('Admin sign-in code email failed', ['admin_id' => $admin->id, 'error' => $e->getMessage()]);
            return false;
        }

        $expires = now()->addMinutes(self::CODE_MINUTES);
        Cache::put($this->challengeKey($challenge), [
            'admin_id' => $admin->id,
            'code' => Hash::make($code),
            'attempts' => 0,
            'sends' => $sends,
            'expires_at' => $expires->timestamp,
        ], $expires);

        return true;
    }

    /** The challenge itself is never stored, only its hash. */
    private function challengeKey(string $challenge): string
    {
        return 'admin-2fa:' . hash('sha256', $challenge);
    }

    private function maskEmail(string $email): string
    {
        [$name, $domain] = explode('@', $email, 2) + [1 => ''];
        return mb_substr($name, 0, 2) . str_repeat('•', max(1, mb_strlen($name) - 2)) . '@' . $domain;
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return ApiResponse::success('Logged out successfully.');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success('Admin retrieved.', $request->user());
    }

    /** Changes your own password and signs out your other devices. */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:10', 'confirmed', 'different:current_password'],
        ]);

        $admin = $request->user();

        if (!Hash::check($data['current_password'], $admin->password)) {
            return ApiResponse::error('Your current password is incorrect.', null, 422);
        }

        $admin->update(['password' => $data['password']]);
        $admin->tokens()->where('id', '!=', $admin->currentAccessToken()->id)->delete();

        return ApiResponse::success('Password changed.');
    }
}