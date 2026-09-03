<?php

namespace App\Mail;

use App\Models\Invitation;
use App\Models\InvitationGuest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $rsvpUrl;

    public function __construct(
        public InvitationGuest $guest,
        public Invitation $invitation
    ) {
        $this->rsvpUrl = config('app.frontend_url') . '/rsvp/' . $invitation->share_token
            . '?guest=' . $guest->id;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You're Invited: {$this->invitation->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invitation',
            with: [
                'guest' => $this->guest,
                'invitation' => $this->invitation,
                'rsvpUrl' => $this->rsvpUrl,
            ],
        );
    }
}
