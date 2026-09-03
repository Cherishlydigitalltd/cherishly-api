<?php

namespace App\Jobs;

use App\Models\SantaParticipant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendSantaMatchEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public SantaParticipant $giver,
        public SantaParticipant $receiver
    ) {}

    public function handle(): void
    {
        Mail::send('emails.santa_match', [
            'giver'    => $this->giver,
            'receiver' => $this->receiver,
            'santa'    => $this->giver->santa,
            'code'     => $this->giver->code,
            'revealUrl'=> config('app.frontend_url') . '/santa/' . $this->giver->santa->share_token,
        ], function ($message) {
            $message->to($this->giver->email, $this->giver->name)
                    ->subject("🎅 Your Secret Santa match is ready!");
        });

        Log::info('Santa match email sent', [
            'giver_id'    => $this->giver->id,
            'receiver_id' => $this->receiver->id,
        ]);
    }
}
