<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserOtp;
use App\Jobs\SendOtpEmail;
use App\Jobs\SendOtpSms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OtpService
{
    private int $expiry;
    private int $maxAttempts;
    private int $lockoutMinutes;
    private bool $smsEnabled;

    public function __construct()
    {
        $this->expiry         = (int) setting('otp_expiry_minutes', 10);
        $this->maxAttempts    = (int) setting('otp_max_attempts', 3);
        $this->lockoutMinutes = (int) setting('otp_lockout_minutes', 15);
        $this->smsEnabled     = setting('sms_enabled', 'false') === 'true';
    }

    /* ── Generate & Send OTP ── */

    public function send(User $user, string $type): void
    {
        // Invalidate any existing unused OTPs of same type
        UserOtp::where('user_id', $user->id)
            ->where('type', $type)
            ->where('is_used', false)
            ->delete();

        $otp = $this->generate();

        UserOtp::create([
            'user_id'    => $user->id,
            'otp'        => $otp,
            'type'       => $type,
            'attempts'   => 0,
            'is_used'    => false,
            'expires_at' => now()->addMinutes($this->expiry),
        ]);

        // Always send email
        SendOtpEmail::dispatch($user, $otp, $type);

        // Send SMS only if enabled and user has phone
        if ($this->smsEnabled && $user->phone) {
            SendOtpSms::dispatch($user, $otp, $type);
        }
    }

    /* ── Verify OTP ── */

    public function verify(User $user, string $otp, string $type): array
    {
        $record = UserOtp::where('user_id', $user->id)
            ->where('type', $type)
            ->where('is_used', false)
            ->latest()
            ->first();

        if (!$record) {
            return ['success' => false, 'message' => 'No OTP found. Please request a new one.'];
        }

        // Check lockout
        if ($record->isLocked()) {
            $remaining = now()->diffInMinutes($record->locked_until, false);
            return ['success' => false, 'message' => "Too many attempts. Try again in {$remaining} minutes."];
        }

        // Check expiry
        if ($record->isExpired()) {
            return ['success' => false, 'message' => 'OTP has expired. Please request a new one.'];
        }

        // Wrong OTP — increment attempts
        if ($record->otp !== $otp) {
            $record->increment('attempts');

            if ($record->attempts >= $this->maxAttempts) {
                $record->update(['locked_until' => now()->addMinutes($this->lockoutMinutes)]);
                return ['success' => false, 'message' => "Too many failed attempts. Try again in {$this->lockoutMinutes} minutes."];
            }

            $remaining = $this->maxAttempts - $record->attempts;
            return ['success' => false, 'message' => "Invalid OTP. {$remaining} attempt(s) remaining."];
        }

        // Mark as used
        $record->update(['is_used' => true, 'used_at' => now()]);

        return ['success' => true, 'message' => 'OTP verified successfully.'];
    }

    /* ── Resend OTP ── */

    public function resend(User $user, string $type): void
    {
        $this->send($user, $type);
    }

    /* ── Private ── */

    private function generate(): string
    {
        return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
