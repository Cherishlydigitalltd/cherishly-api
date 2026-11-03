<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Jobs\SettlePendingFunds;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');




Schedule::job(new SettlePendingFunds)->dailyAt('08:00');

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
