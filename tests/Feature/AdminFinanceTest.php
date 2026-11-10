<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Contribution;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminFinanceTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function walletFor(array $balances = []): Wallet
    {
        $this->n++;
        $user = User::create([
            'first_name' => "User{$this->n}", 'last_name' => 'Test', 'email' => "u{$this->n}@x.test",
            'password' => 'secret-pass', 'is_email_verified' => true,
        ]);

        return Wallet::create($balances + [
            'user_id' => $user->id, 'balance' => 0, 'available_balance' => 0,
            'bank_name' => 'Access Bank', 'account_number' => '0123456789', 'account_name' => 'USER TEST',
        ]);
    }

    private function tx(Wallet $wallet, string $type, float $amount, string $status, string $reference, array $extra = []): Transaction
    {
        return $wallet->transactions()->create($extra + [
            'user_id' => $wallet->user_id, 'type' => $type, 'amount' => $amount,
            'description' => 'Test', 'reference' => $reference, 'status' => $status,
        ]);
    }

    private function signIn(): void
    {
        Sanctum::actingAs(Admin::create([
            'first_name' => 'A', 'last_name' => 'D', 'email' => 'admin@x.test',
            'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true,
        ]));
    }

    public function test_customers_and_guests_cannot_see_finance_data(): void
    {
        $this->getJson('/api/admin/finance/summary')->assertStatus(401);
        $this->getJson('/api/admin/transactions')->assertStatus(401);

        $wallet = $this->walletFor();
        Sanctum::actingAs($wallet->user);
        $this->getJson('/api/admin/wallets')->assertStatus(401);
    }

    public function test_summary_totals_wallets_and_withdrawals(): void
    {
        $a = $this->walletFor(['balance' => 5000, 'available_balance' => 3000, 'pending_settlement' => 2000, 'total_received' => 9000, 'total_withdrawn' => 4000]);
        $b = $this->walletFor(['balance' => 1000, 'available_balance' => 1000, 'total_received' => 1000]);

        $this->tx($a, 'debit', 4000, 'successful', 'WD-1', ['fee_amount' => 50]);
        $this->tx($a, 'debit', 700, 'pending', 'WD-2');
        $old = $this->tx($b, 'debit', 300, 'pending', 'WD-3');
        $old->forceFill(['created_at' => now()->subDays(2)])->save();
        $this->tx($b, 'credit', 1000, 'successful', 'CHR_GIFT_1_x');

        $this->signIn();
        $data = $this->getJson('/api/admin/finance/summary')->assertOk()->json('data');

        $this->assertSame(2, $data['wallets']['count']);
        $this->assertEquals(6000, $data['wallets']['balance']);
        $this->assertEquals(4000, $data['wallets']['available_balance']);
        $this->assertEquals(2, $data['withdrawals']['pending']['count']);
        $this->assertEquals(1000, $data['withdrawals']['pending']['amount']);
        $this->assertEquals(50, $data['fees']['withdrawal_fees']);
        $this->assertSame(1, $data['stuck_withdrawals']);
    }

    public function test_wallets_list_searches_and_sorts_by_balance(): void
    {
        $this->walletFor(['balance' => 100]);
        $rich = $this->walletFor(['balance' => 900]);
        $this->walletFor();

        $this->signIn();
        $rows = $this->getJson('/api/admin/wallets')->assertOk()->json('data.data');
        $this->assertSame($rich->id, $rows[0]['id']);
        $this->assertSame('u2@x.test', $rows[0]['user']['email']);

        $this->assertCount(2, $this->getJson('/api/admin/wallets?has_balance=1')->json('data.data'));
        $this->assertCount(1, $this->getJson('/api/admin/wallets?search=u3@x')->json('data.data'));
    }

    public function test_transactions_filter_by_kind_status_user_and_search(): void
    {
        $a = $this->walletFor();
        $b = $this->walletFor();
        $this->tx($a, 'credit', 1000, 'successful', 'CHR_GIFT_1_aaa');
        $this->tx($a, 'credit', 2000, 'successful', 'CHR_MON_2_bbb');
        $this->tx($a, 'debit', 500, 'pending', 'WD-AAA');
        $this->tx($b, 'debit', 800, 'failed', 'WD-BBB');

        $this->signIn();

        $res = $this->getJson('/api/admin/transactions?kind=withdrawal')->assertOk();
        $this->assertCount(2, $res->json('data.page.data'));
        $this->assertEquals(1300, $res->json('data.totals.amount'));

        $this->assertCount(1, $this->getJson('/api/admin/transactions?kind=withdrawal&status=pending')->json('data.page.data'));
        $this->assertCount(1, $this->getJson('/api/admin/transactions?kind=cash')->json('data.page.data'));
        $this->assertCount(3, $this->getJson("/api/admin/transactions?wallet_id={$a->id}")->json('data.page.data'));
        $this->assertCount(1, $this->getJson('/api/admin/transactions?search=u2@x')->json('data.page.data'));
        $this->assertSame('u1@x.test', $this->getJson('/api/admin/transactions?search=WD-AAA')->json('data.page.data.0.user.email'));
        $this->getJson('/api/admin/transactions?status=bogus')->assertStatus(422);
    }

    public function test_transaction_detail_hides_payer_bvn(): void
    {
        $wallet = $this->walletFor();
        $tx = $this->tx($wallet, 'credit', 1000, 'successful', 'CHR_GIFT_9_zzz');

        // A contribution row with this reference (gift isn't needed for the lookup).
        \Illuminate\Support\Facades\DB::table('contributions')->insert([
            'gift_id' => $this->giftId($wallet),
            'donor_name' => 'Payer', 'donor_email' => 'payer@x.test', 'amount' => 1000,
            'bvn' => encrypt('22222222222'), 'payment_method' => 'paystack',
            'payment_reference' => 'CHR_GIFT_9_zzz', 'payment_status' => 'successful',
            'payment_meta' => json_encode(['secret' => 'x']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->signIn();
        $res = $this->getJson("/api/admin/transactions/{$tx->id}")->assertOk();
        $this->assertSame('Payer', $res->json('data.payment.donor_name'));
        $this->assertArrayNotHasKey('bvn', $res->json('data.payment'));
        $this->assertArrayNotHasKey('payment_meta', $res->json('data.payment'));
    }

    private function giftId(Wallet $wallet): int
    {
        $registry = \App\Models\GiftRegistry::create(['user_id' => $wallet->user_id, 'name' => 'Our wedding']);

        return \App\Models\Gift::forceCreate([
            'registry_id' => $registry->id, 'name' => 'Toaster', 'price' => 1000,
        ])->id;
    }
}
