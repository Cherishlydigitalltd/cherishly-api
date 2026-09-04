<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\EventGuest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendEventInvitationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public EventGuest $guest,
        public Event $event
    ) {}

    public function handle(): void
    {
        $rsvpUrl = config('app.frontend_url') . '/event/' . $this->event->share_token;

        Mail::send('emails.event_invitation', [
            'guest'   => $this->guest,
            'event'   => $this->event,
            'rsvpUrl' => $rsvpUrl,
        ], function ($message) {
            $message->to($this->guest->email, $this->guest->full_name)
                    ->subject("You're Invited: {$this->event->title}");
        });

        Log::info('Event invitation email sent', [
            'guest_id' => $this->guest->id,
            'event_id' => $this->event->id,
            'email'    => $this->guest->email,
        ]);
    }
}
