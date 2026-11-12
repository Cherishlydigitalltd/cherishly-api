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

// Delete sign-in tokens that expired more than a day ago.
Schedule::command('sanctum:prune-expired --hours=24')->daily()->name('prune-expired-tokens');

// Nightly database backup and wallet check. Each runs hourly between 01:00
// and 05:00 UTC (2–6am Lagos) but only does its work once a day, so one
// missed cron ping doesn't skip a night. Backups first, then the check.
Schedule::command('db:backup')->hourlyAt(0)->between('01:00', '05:00')->name('database-backup')->withoutOverlapping(30);
Schedule::command('wallets:check')->hourlyAt(0)->between('02:00', '05:00')->name('wallet-check')->withoutOverlapping(30);
