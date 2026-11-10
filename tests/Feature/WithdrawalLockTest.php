<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Services\GatewayService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WithdrawalLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gateway.url' => 'https://gateway.test']);
        Http::fake(['gateway.test/api/Payouts/initiate' => Http::response(['data' => ['status' => 'pending']])]);
    }

    private function walletWith(float $available): Wallet
    {
        $user = User::create([
            'first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'ada@x.test',
            'password' => 'secret-pass', 'is_email_verified' => true,
            'withdrawal_fee_rate' => 0, // keep the numbers simple
        ]);

        return Wallet::create([
            'user_id' => $user->id, 'balance' => $available, 'available_balance' => $available,
            'bank_name' => 'Access Bank', 'bank_code' => '044',
            'account_number' => '0123456789', 'account_name' => 'ADA OBI',
        ]);
    }

    /**
     * A second request sent at the same moment as the first reads the
     * balance before the first one deducts it. Simulate that by handing the
     * service an out-of-date copy of the wallet: the withdrawal must be
     * refused using the real, locked balance.
     */
    public function test_withdrawal_rechecks_the_balance_on_the_locked_row(): void
    {
        $wallet = $this->walletWith(10000);
        $stale = $wallet->fresh();

        // The "first" request already took the money.
        $wallet->update(['balance' => 2000, 'available_balance' => 2000]);

        $service = new class(app(GatewayService::class), $stale) extends WalletService {
            public function __construct(GatewayService $g, private Wallet $stale) { parent::__construct($g); }
            public function getWallet(User $user): Wallet { return $this->stale; }
        };

        $result = $service->withdraw($wallet->user, 8000);

        $this->assertFalse($result['success']);
        $this->assertSame('Insufficient available balance.', $result['message']);
        $this->assertEquals(2000, $wallet->fresh()->available_balance);
        $this->assertSame(0, $wallet->transactions()->count());
        Http::assertNothingSent();
    }

    public function test_back_to_back_withdrawals_cannot_overdraw(): void
    {
        $wallet = $this->walletWith(10000);
        Sanctum::actingAs($wallet->user);

        $this->postJson('/api/wallet/withdraw', ['amount' => 8000])->assertOk();
        $this->postJson('/api/wallet/withdraw', ['amount' => 8000])->assertStatus(400);

        $wallet->refresh();
        $this->assertEquals(2000, $wallet->available_balance);
        $this->assertEquals(2000, $wallet->balance);
        $this->assertSame(1, $wallet->transactions()->where('type', 'debit')->count());
    }

    public function test_a_normal_withdrawal_still_works(): void
    {
        $wallet = $this->walletWith(5000);
        Sanctum::actingAs($wallet->user);

        $ref = $this->postJson('/api/wallet/withdraw', ['amount' => 3000])->assertOk()->json('data.reference');

        $this->assertStringStartsWith('WD-', $ref);
        $this->assertEquals(2000, $wallet->fresh()->available_balance);
        $this->assertDatabaseHas('transactions', ['reference' => $ref, 'status' => 'pending', 'amount' => 3000]);
    }
}
