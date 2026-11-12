<?php

namespace Tests\Feature;

use App\Models\GiftRegistry;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorOrder;
use App\Models\Wallet;
use App\Services\MarketplaceService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * When we can't tell whether the payout provider sent the money, nothing
 * may be refunded or retried until its webhook says what happened.
 */
class PayoutOutcomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gateway.url' => 'https://gateway.test']);
    }

    private function fakePayout(callable|\GuzzleHttp\Promise\PromiseInterface $response): void
    {
        Http::fake(['gateway.test/api/Payouts/initiate' => $response]);
    }

    private function timeout(): callable
    {
        return fn () => throw new ConnectionException('cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received');
    }

    private function wallet(float $available = 10000): Wallet
    {
        $user = User::create([
            'first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'ada@x.test',
            'password' => 'secret-pass', 'is_email_verified' => true, 'withdrawal_fee_rate' => 0,
        ]);

        return Wallet::create([
            'user_id' => $user->id, 'balance' => $available, 'available_balance' => $available,
            'bank_name' => 'Access Bank', 'bank_code' => '044',
            'account_number' => '0123456789', 'account_name' => 'ADA OBI', 'bvn_verified' => true,
        ]);
    }

    private function withdraw(Wallet $wallet, float $amount)
    {
        Sanctum::actingAs($wallet->user);
        return $this->postJson('/api/wallet/withdraw', ['amount' => $amount]);
    }

    public function test_timeout_leaves_the_withdrawal_pending_and_the_webhook_refunds_once(): void
    {
        $this->fakePayout($this->timeout());
        $wallet = $this->wallet();

        $ref = $this->withdraw($wallet, 8000)->assertOk()
            ->assertJsonPath('message', 'Your withdrawal is being processed. If it doesn’t go through, the money will return to your wallet.')
            ->json('data.reference');

        // Money stays out of the wallet: it may already be on its way.
        $this->assertEquals(2000, $wallet->fresh()->available_balance);
        $this->assertDatabaseHas('transactions', ['reference' => $ref, 'status' => 'pending']);

        // The provider later reports it failed (and resends the webhook).
        $service = app(WalletService::class);
        $this->assertTrue($service->confirmWithdrawal($ref, 'failed'));
        $this->assertTrue($service->confirmWithdrawal($ref, 'failed'));

        $this->assertEquals(10000, $wallet->fresh()->available_balance);
        $this->assertEquals(10000, $wallet->fresh()->balance);
        $this->assertDatabaseHas('transactions', ['reference' => $ref, 'status' => 'failed']);
    }

    public function test_timeout_then_success_webhook_completes_without_refund(): void
    {
        $this->fakePayout($this->timeout());
        $wallet = $this->wallet();

        $ref = $this->withdraw($wallet, 8000)->assertOk()->json('data.reference');
        app(WalletService::class)->confirmWithdrawal($ref, 'successful');

        $wallet->refresh();
        $this->assertEquals(2000, $wallet->available_balance);
        $this->assertEquals(8000, $wallet->total_withdrawn);
    }

    public function test_provider_server_error_is_treated_as_unknown(): void
    {
        $this->fakePayout(Http::response(['message' => 'Internal error'], 502));
        $wallet = $this->wallet();

        $this->withdraw($wallet, 8000)->assertOk();
        $this->assertEquals(2000, $wallet->fresh()->available_balance);
        $this->assertSame('pending', $wallet->transactions()->first()->status);
    }

    public function test_clear_refusal_refunds_at_once_and_a_late_webhook_does_not_refund_again(): void
    {
        $this->fakePayout(Http::response(['message' => 'Invalid account number'], 422));
        $wallet = $this->wallet();

        $this->withdraw($wallet, 8000)->assertStatus(400)->assertJsonPath('message', 'Invalid account number');

        $tx = $wallet->transactions()->first();
        $this->assertSame('failed', $tx->status);
        $this->assertEquals(10000, $wallet->fresh()->available_balance);

        app(WalletService::class)->confirmWithdrawal($tx->reference, 'failed');
        $this->assertEquals(10000, $wallet->fresh()->available_balance);
    }

    public function test_unreachable_provider_refunds_because_nothing_was_sent(): void
    {
        $this->fakePayout(fn () => throw new ConnectionException('cURL error 7: Failed to connect to gateway.test port 443'));
        $wallet = $this->wallet();

        $this->withdraw($wallet, 8000)->assertStatus(400);
        $this->assertEquals(10000, $wallet->fresh()->available_balance);
        $this->assertSame('failed', $wallet->transactions()->first()->status);
    }

    /* ── Vendor payouts ── */

    private function completedOrder(): VendorOrder
    {
        $celebrant = User::create(['first_name' => 'C', 'last_name' => 'B', 'email' => 'c@x.test', 'password' => 'secret-pass']);
        $vendorUser = User::create(['first_name' => 'V', 'last_name' => 'D', 'email' => 'v@x.test', 'password' => 'secret-pass']);
        \App\Models\Wallet::create(['user_id' => $vendorUser->id, 'bvn_verified' => true]); // owner's BVN checked
        $vendor = Vendor::create([
            'user_id' => $vendorUser->id, 'business_name' => 'Gifts Ltd', 'contact_name' => 'Bola', 'phone' => '08000000000',
            'email' => 'shop@x.test', 'address' => '1 Shop St', 'city' => 'Ikeja', 'state' => 'Lagos',
            'delivery_states' => ['Lagos'], 'status' => 'approved', 'approved_at' => now(),
            'bank_code' => '058', 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'GIFTS LTD',
        ]);
        $registry = GiftRegistry::create(['user_id' => $celebrant->id, 'name' => 'Our wedding']);
        $gift = \App\Models\Gift::forceCreate(['registry_id' => $registry->id, 'name' => 'Blender', 'price' => 50000]);

        return VendorOrder::forceCreate([
            'reference' => 'VO-TEST1', 'vendor_id' => $vendor->id, 'gift_id' => $gift->id,
            'registry_id' => $registry->id, 'user_id' => $celebrant->id, 'item_name' => 'Blender',
            'amount' => 50000, 'commission_rate' => 10, 'commission_amount' => 5000, 'vendor_amount' => 45000,
            'status' => 'completed', 'completed_at' => now(), 'payout_status' => 'none',
        ]);
    }

    public function test_vendor_payout_timeout_stays_processing_so_it_cannot_be_retried_twice(): void
    {
        $calls = 0;
        $this->fakePayout(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received');
        });
        $order = app(MarketplaceService::class)->payVendor($this->completedOrder());

        $this->assertSame('processing', $order->payout_status);
        $ref = $order->payout_reference;

        // A second attempt (scheduler, admin retry) does nothing while processing.
        app(MarketplaceService::class)->payVendor($order);
        $this->assertSame($ref, $order->fresh()->payout_reference);
        $this->assertSame(1, $calls);

        // The webhook settles it.
        app(MarketplaceService::class)->confirmPayout($ref, 'successful');
        $this->assertSame('paid', $order->fresh()->payout_status);
    }

    public function test_vendor_payout_clear_refusal_is_marked_failed(): void
    {
        $this->fakePayout(Http::response(['message' => 'Invalid account'], 400));
        $order = app(MarketplaceService::class)->payVendor($this->completedOrder());

        $this->assertSame('failed', $order->payout_status);
    }
}
