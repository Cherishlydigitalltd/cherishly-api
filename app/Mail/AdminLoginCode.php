<?php

namespace App\Mail;

use App\Models\Admin;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The 6-digit code an admin types after their password. Sent immediately, not queued. */
class AdminLoginCode extends Mailable
{
    public function __construct(
        public Admin $admin,
        public string $code,
        public string $ip,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your Cherishly admin sign-in code: {$this->code}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.admin_login_code');
    }
}
