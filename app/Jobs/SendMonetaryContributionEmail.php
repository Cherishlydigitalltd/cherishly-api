<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendMonetaryContributionEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public User $owner,
        public string $donorName,
        public float $amount,
        public string $giftTitle,
        public string $dashboardLink,
    ) {
    }

    public function handle(): void
    {
        Mail::send('emails.monetary_contribution', [
            'owner' => $this->owner,
            'donorName' => $this->donorName,
            'amount' => $this->amount,
            'giftTitle' => $this->giftTitle,
            'dashboardLink' => $this->dashboardLink,
        ], function ($message) {
            $message->to($this->owner->email, $this->owner->full_name)
                ->subject('💰 New donation received on Cherishly!');
        });
    }
}