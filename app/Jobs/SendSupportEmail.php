<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendSupportEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $message,
    ) {
    }

    public function handle(): void
    {
        $userMessage = $this->message;
        $user = $this->user;

        // Email to support team
        Mail::send('emails.support_request', [
            'user' => $user,
            'userMessage' => $userMessage,
        ], function ($mail) use ($user) {
            $mail->to('support@cherishlyng.com', 'Cherishly Support')
                ->subject("Support Request from {$user->full_name}");
        });

        // Confirmation email to user
        Mail::send('emails.support_confirmation', [
            'user' => $user,
        ], function ($mail) use ($user) {
            $mail->to($user->email, $user->full_name)
                ->subject('We received your support request — Cherishly');
        });
    }
}