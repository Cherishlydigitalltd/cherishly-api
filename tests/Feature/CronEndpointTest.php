<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CronEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_is_hidden_without_a_configured_secret(): void
    {
        config(['services.cron.secret' => null]);

        $this->getJson('/api/cron/run')->assertNotFound();
        $this->getJson('/api/cron/run?key=')->assertNotFound();
    }

    public function test_wrong_key_is_rejected(): void
    {
        config(['services.cron.secret' => 'right-key']);

        $this->getJson('/api/cron/run?key=wrong-key')->assertNotFound();
        $this->getJson('/api/cron/run')->assertNotFound();
    }

    public function test_correct_key_runs_the_scheduler(): void
    {
        config(['services.cron.secret' => 'right-key']);

        $this->getJson('/api/cron/run?key=right-key')
            ->assertOk()
            ->assertJsonPath('message', 'OK');
    }

    public function test_overlapping_call_is_skipped(): void
    {
        config(['services.cron.secret' => 'right-key']);
        $lock = Cache::lock('cron-run', 120);
        $lock->get();

        $this->getJson('/api/cron/run?key=right-key')
            ->assertOk()
            ->assertJsonPath('message', 'Already running.');

        $lock->release();
    }

    public function test_settlement_is_scheduled_hourly(): void
    {
        Artisan::call('schedule:list');
        $out = Artisan::output();

        $this->assertMatchesRegularExpression('/0 \* \* \* \*.*SettlePendingFunds|settle-pending-funds/', $out);
    }
}
