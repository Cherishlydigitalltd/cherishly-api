<?php

namespace App\Jobs;

use App\Models\Invitation;
use App\Models\InvitationGuest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sent when an invitation guest RSVPs "attending": their QR entry pass,
 * which the host scans at the door with the mobile app.
 */
class SendInvitationPassEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public InvitationGuest $guest,
        public Invitation $invitation
    ) {}

    public function handle(): void
    {
        $passUrl = config('app.frontend_url') . '/pass/' . $this->guest->qr_token;

        Mail::send('emails.invitation_pass', [
            'guest'      => $this->guest,
            'invitation' => $this->invitation,
            'passUrl'    => $passUrl,
        ], function ($message) {
            $message->to($this->guest->email, $this->guest->full_name)
                ->subject("You're confirmed: your pass for {$this->invitation->title}");
        });

        Log::info('Invitation pass email sent', [
            'guest_id'      => $this->guest->id,
            'invitation_id' => $this->invitation->id,
        ]);
    }
}
