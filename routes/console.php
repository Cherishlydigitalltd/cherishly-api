<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Jobs\SettlePendingFunds;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');




// Hourly rather than once a day: it only settles funds whose available_at
// has passed, so extra runs are harmless, and a missed ping (the scheduler
// is driven by an outside service hitting /api/cron/run) no longer delays
// settlement by a whole day.
Schedule::job(new SettlePendingFunds)->hourly()->name('settle-pending-funds')->withoutOverlapping();

// Invitations the host scheduled for later.
Schedule::call(fn () => app(\App\Services\InvitationService::class)->sendDueScheduled())
    ->everyMinute()
    ->name('send-scheduled-invitations')
    ->withoutOverlapping();

// Vendor orders marked delivered that the celebrant didn't confirm or dispute.
Schedule::call(fn () => app(\App\Services\MarketplaceService::class)->autoComplete())
    ->hourly()
    ->name('complete-delivered-vendor-orders')
    ->withoutOverlapping();
