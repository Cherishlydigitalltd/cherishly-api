<?php

namespace Tests\Feature;

use App\Jobs\SettlePendingFunds;
use App\Models\MonetaryContribution;
use App\Models\MonetaryGift;
use App\Models\User;
use App\Models\Wallet;
use App\Services\MonetaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class NightlyChecksTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'app.alert_email' => 'alerts@x.test', 'cache.default' => 'array']);

        // Keep test backups out of the real storage folder.
        $this->storage = sys_get_temp_dir() . '/cherishly-test-storage-' . uniqid();
        File::ensureDirectoryExists($this->storage . '/app');
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    /** @return \Symfony\Component\Mime\Email[] */
    private function sent(): array
    {
        return array_map(fn ($m) => $m->getOriginalMessage(), app('mailer')->getSymfonyTransport()->messages()->all());
    }

    private function wallet(string $email, array $attrs = []): Wallet
    {
        $user = User::create([
            'first_name' => 'Ada', 'last_name' => 'Obi', 'email' => $email,
            'password' => 'secret-pass', 'is_email_verified' => true,
        ]);

        return Wallet::create(['user_id' => $user->id] + $attrs);
    }

    private function credit(Wallet $w, float $amount, array $extra = []): void
    {
        $w->transactions()->create($extra + [
            'user_id' => $w->user_id, 'type' => 'credit', 'amount' => $amount,
            'reference' => 'T-' . uniqid(), 'status' => 'successful', 'description' => 'Test credit',
        ]);
    }

    /* ── Wallet check ── */

    public function test_wallets_that_add_up_send_no_email(): void
    {
        $w = $this->wallet('ok@x.test', ['balance' => 7000, 'available_balance' => 5000, 'pending_settlement' => 2000]);
        $this->credit($w, 5000, ['available_at' => now()->subHour(), 'settled_at' => now()]);
        $this->credit($w, 2000, ['available_at' => now()->addDay()]);
        $w->transactions()->create(['user_id' => $w->user_id, 'type' => 'debit', 'amount' => 3000, 'reference' => 'WD-1', 'status' => 'failed', 'description' => 'Withdrawal']);

        $this->artisan('wallets:check')->assertSuccessful();

        $this->assertCount(0, $this->sent());
    }

    public function test_mismatched_wallet_and_stuck_withdrawal_are_emailed(): void
    {
        // Balance edited by hand: ₦10,000 more than its transactions explain.
        $bad = $this->wallet('bad@x.test', ['balance' => 15000, 'available_balance' => 15000]);
        $this->credit($bad, 5000);

        // Money in the balance that never became available or settling.
        $stranded = $this->wallet('stranded@x.test', ['balance' => 4000, 'available_balance' => 0, 'pending_settlement' => 0]);
        $this->credit($stranded, 4000);

        $stuck = $this->wallet('stuck@x.test', ['balance' => 0]);
        $this->credit($stuck, 100);
        $tx = $stuck->transactions()->create(['user_id' => $stuck->user_id, 'type' => 'debit', 'amount' => 100, 'reference' => 'WD-OLD', 'status' => 'pending', 'description' => 'Withdrawal']);
        $tx->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->artisan('wallets:check')->assertSuccessful();

        $mails = $this->sent();
        $this->assertCount(1, $mails);
        $this->assertSame('[Cherishly API] Wallet check: 2 wallets to look at, 1 stuck withdrawal', $mails[0]->getSubject());
        $body = $mails[0]->getTextBody();
        $this->assertStringContainsString("Wallet #{$bad->id}", $body);
        $this->assertStringContainsString('+₦10,000.00', $body);
        $this->assertStringContainsString("Wallet #{$stranded->id}", $body);
        $this->assertStringContainsString("can't be withdrawn", $body);
        $this->assertStringContainsString('WD-OLD', $body);
        $this->assertStringNotContainsString("Wallet #{$stuck->id}", $body);
    }

    public function test_check_runs_once_a_day_unless_forced(): void
    {
        $this->wallet('bad@x.test', ['balance' => 500]);

        $this->artisan('wallets:check');
        $this->artisan('wallets:check');
        $this->assertCount(1, $this->sent());

        $this->artisan('wallets:check --force');
        $this->assertCount(2, $this->sent());
    }

    /* ── Cash gifts settle like other gifts ── */

    public function test_cash_gift_donation_becomes_available_the_next_day(): void
    {
        $w = $this->wallet('host@x.test', ['balance' => 0]);
        $gift = MonetaryGift::create(['user_id' => $w->user_id, 'title' => 'Wedding fund', 'target_amount' => 100000]);
        MonetaryContribution::create([
            'monetary_gift_id' => $gift->id, 'donor_name' => 'Tobi', 'donor_email' => 'tobi@x.test',
            'amount' => 20000, 'net_amount' => 20000, 'payment_method' => 'paystack', 'payment_reference' => 'CHR_MON_TEST', 'payment_status' => 'pending',
        ]);

        app(MonetaryService::class)->confirmPayment('CHR_MON_TEST', 'successful');

        $w->refresh();
        $this->assertEquals(20000, $w->balance);
        $this->assertEquals(0, $w->available_balance);
        $this->assertEquals(20000, $w->pending_settlement);

        $this->travel(25)->hours();
        (new SettlePendingFunds)->handle();

        $w->refresh();
        $this->assertEquals(20000, $w->available_balance);
        $this->assertEquals(0, $w->pending_settlement);

        $this->artisan('wallets:check --force')->assertSuccessful();
        $checks = array_filter($this->sent(), fn ($m) => str_contains((string) $m->getSubject(), 'Wallet check'));
        $this->assertCount(0, $checks); // (the donation itself sends the host a "you received" email)
    }

    /* ── Backups ── */

    public function test_backup_writes_a_restorable_file_once_a_day(): void
    {
        config(['backup.pg_dump' => '/nonexistent/pg_dump']); // force the PHP dumper
        $this->wallet('ada@x.test', ['balance' => 1234.5]);

        $this->artisan('db:backup')->assertSuccessful();
        $this->artisan('db:backup')->expectsOutput("Today's backup already exists.")->assertSuccessful();

        $files = glob(storage_path('app/backups/cherishly-*.sql.gz'));
        $this->assertCount(1, $files);
        $sql = gzdecode(file_get_contents($files[0]));
        $this->assertStringContainsString('CREATE TABLE "wallets"', $sql);
        $this->assertStringContainsString("'ada@x.test'", $sql);
        $this->assertStringContainsString("SELECT setval('wallets_id_seq'", $sql);
        $this->assertStringEndsWith("COMMIT;\n", $sql);
        $this->assertFileExists(storage_path('app/backups/.htaccess'));
    }

    public function test_old_backups_are_removed_but_the_newest_three_kept(): void
    {
        config(['backup.pg_dump' => '/nonexistent/pg_dump', 'backup.keep_days' => 14]);
        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir);
        foreach ([40, 30, 20, 2] as $daysAgo) {
            $f = $dir . '/cherishly-' . now()->subDays($daysAgo)->format('Y-m-d-His') . '.sql.gz';
            file_put_contents($f, 'x');
            touch($f, now()->subDays($daysAgo)->getTimestamp());
        }

        $this->artisan('db:backup')->assertSuccessful();

        // Today's + 2 days + 20 days kept (newest three, then by age), 30 and 40 removed.
        $this->assertCount(3, glob($dir . '/cherishly-*.sql.gz'));
        $this->assertEmpty(glob($dir . '/cherishly-' . now()->subDays(40)->format('Y-m-d') . '-*'));
    }

    public function test_failed_backup_emails_once_a_day(): void
    {
        config(['database.connections.broken' => ['driver' => 'sqlite', 'database' => ':memory:'], 'database.default' => 'broken']);

        $this->artisan('db:backup')->assertFailed();
        $this->artisan('db:backup')->assertFailed();

        $mails = $this->sent();
        $this->assertCount(1, $mails);
        $this->assertSame('[Cherishly API] Database backup failed', $mails[0]->getSubject());
        $this->assertEmpty(glob(storage_path('app/backups/*.sql.gz*')));
    }
}
