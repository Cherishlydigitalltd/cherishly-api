<?php

namespace App\Jobs;

use App\Models\Invitation;
use App\Models\InvitationGuest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendInvitationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        public InvitationGuest $guest,
        public Invitation $invitation
    ) {
    }

    public function handle(): void
    {
        $rsvpUrl = config('app.frontend_url') . '/rsvp/' . $this->invitation->share_token
            . '?guest=' . $this->guest->id;

        Mail::send('emails.invitation', [
            'guest' => $this->guest,
            'invitation' => $this->invitation,
            'rsvpUrl' => $rsvpUrl,
        ], function ($message) {
            $message->to($this->guest->email, $this->guest->full_name)
                ->subject("You're Invited: {$this->invitation->title}");
        });

        Log::info('Invitation email sent', [
            'guest_id' => $this->guest->id,
            'invitation_id' => $this->invitation->id,
            'email' => $this->guest->email,
        ]);
    }
}
