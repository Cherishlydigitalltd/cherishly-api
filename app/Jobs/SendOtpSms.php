<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendOtpSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public User $user,
        public string $otp,
        public string $type
    ) {}

    public function handle(SmsService $smsService): void
    {
        $message = "Your Cherishly OTP is: {$this->otp}. Valid for 10 minutes. Do not share.";
        $smsService->send($this->user->phone, $message);
    }
}