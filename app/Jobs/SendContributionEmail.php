<?php

namespace App\Jobs;

use App\Models\Contribution;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendContributionEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public User $owner,
        public string $donorName,
        public float $amount,
        public string $giftName,
        public string $registryName,
        public string $dashboardLink,
    ) {
    }

    public function handle(): void
    {
        Mail::send('emails.contribution', [
            'owner' => $this->owner,
            'donorName' => $this->donorName,
            'amount' => $this->amount,
            'giftName' => $this->giftName,
            'registryName' => $this->registryName,
            'dashboardLink' => $this->dashboardLink,
        ], function ($message) {
            $message->to($this->owner->email, $this->owner->full_name)
                ->subject('🎁 New contribution received on Cherishly!');
        });
    }
}