<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendOtpEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $otp,
        public string $type
    ) {}

    public function handle(): void
    {
        $subject = match ($this->type) {
            'email_verification'    => 'Verify your Cherishly email',
            'password_reset'        => 'Reset your Cherishly password',
            'identity_verification' => 'Cherishly identity verification',
            default                 => 'Your Cherishly OTP',
        };

        Mail::send('emails.otp', [
            'user' => $this->user,
            'otp'  => $this->otp,
            'type' => $this->type,
        ], function ($message) use ($subject) {
            $message->to($this->user->email, $this->user->full_name)
                    ->subject($subject);
        });
    }
}