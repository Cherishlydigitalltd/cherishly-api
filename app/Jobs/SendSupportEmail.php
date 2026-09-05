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
        // Email to support team
        Mail::send('emails.support_request', [
            'user' => $this->user,
            'message' => $this->message,
        ], function ($message) {
            $message->to('support@cherishlyng.com', 'Cherishly Support')
                ->subject("Support Request from {$this->user->full_name}");
        });

        // Confirmation email to user
        Mail::send('emails.support_confirmation', [
            'user' => $this->user,
        ], function ($message) {
            $message->to($this->user->email, $this->user->full_name)
                ->subject('We received your support request — Cherishly');
        });
    }
}