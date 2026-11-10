<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErrorAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'app.alert_email' => 'alerts@x.test', 'cache.default' => 'array']);

        Route::middleware('api')->get('/api/_test/boom', fn () => throw new \RuntimeException('Database fell over'));
        Route::middleware('api')->get('/api/_test/validation', fn () => request()->validate(['x' => 'required']));
    }

    /** @return \Symfony\Component\Mime\Email[] */
    private function sent(): array
    {
        return array_map(
            fn ($m) => $m->getOriginalMessage(),
            app('mailer')->getSymfonyTransport()->messages()->all()
        );
    }

    public function test_server_error_emails_an_alert_without_request_data(): void
    {
        $this->getJson('/api/_test/boom?password=hunter2')->assertStatus(500);

        $mails = $this->sent();
        $this->assertCount(1, $mails);
        $this->assertSame('alerts@x.test', $mails[0]->getTo()[0]->getAddress());
        $this->assertStringContainsString('RuntimeException: Database fell over', $mails[0]->getSubject());

        $body = $mails[0]->getTextBody();
        $this->assertStringContainsString('GET api/_test/boom', $body);
        $this->assertStringNotContainsString('hunter2', $body);
    }

    public function test_the_same_error_is_emailed_once_per_quiet_period(): void
    {
        $this->getJson('/api/_test/boom')->assertStatus(500);
        $this->getJson('/api/_test/boom')->assertStatus(500);
        $this->assertCount(1, $this->sent());

        $this->travel(16)->minutes();
        $this->getJson('/api/_test/boom')->assertStatus(500);
        $this->assertCount(2, $this->sent());
    }

    public function test_everyday_errors_send_nothing(): void
    {
        $this->getJson('/api/no-such-route')->assertNotFound();
        $this->getJson('/api/_test/validation')->assertStatus(422);
        $this->getJson('/api/auth/me')->assertStatus(401);

        $this->assertCount(0, $this->sent());
    }

    public function test_alerts_are_off_without_an_address(): void
    {
        config(['app.alert_email' => null]);
        $this->getJson('/api/_test/boom')->assertStatus(500);
        $this->assertCount(0, $this->sent());
    }

    public function test_test_alert_url_needs_the_cron_key(): void
    {
        config(['services.cron.secret' => 'right-key']);

        $this->getJson('/api/cron/test-alert?key=wrong')->assertNotFound();
        $this->assertCount(0, $this->sent());

        $this->getJson('/api/cron/test-alert?key=right-key')->assertOk();
        $this->getJson('/api/cron/test-alert?key=right-key')->assertOk(); // repeatable
        $this->assertCount(2, $this->sent());
        $this->assertStringContainsString('error alerts work', $this->sent()[0]->getSubject());
    }
}
