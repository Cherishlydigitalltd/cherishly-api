<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/** Order and vendor emails: a few lines of text and an optional button. */
class SendMarketplaceEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $to,
        public string $name,
        public string $subject,
        public array $lines,
        public ?string $button = null,
        public ?string $url = null,
    ) {}

    public function handle(): void
    {
        Mail::send('emails.marketplace', [
            'subject' => $this->subject,
            'name' => $this->name,
            'lines' => $this->lines,
            'button' => $this->button,
            'url' => $this->url,
        ], function ($message) {
            $message->to($this->to, $this->name)->subject($this->subject);
        });
    }
}
