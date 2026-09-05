<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendContactEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public array $data)
    {
    }

    public function handle(): void
    {
        $data = $this->data;

        // Email to support team
        Mail::send('emails.contact_request', ['data' => $data], function ($mail) use ($data) {
            $mail->to('support@cherishlyng.com', 'Cherishly Support')
                ->subject("Contact Form: {$data['first_name']} {$data['last_name']}")
                ->replyTo($data['email'], "{$data['first_name']} {$data['last_name']}");
        });

        // Confirmation to user
        Mail::send('emails.contact_confirmation', ['data' => $data], function ($mail) use ($data) {
            $mail->to($data['email'], "{$data['first_name']} {$data['last_name']}")
                ->subject('We received your message — Cherishly');
        });
    }
}