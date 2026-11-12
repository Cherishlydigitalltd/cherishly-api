<?php

namespace Tests\Feature;

use App\Models\GiftRegistry;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorOrder;
use App\Models\Wallet;
use App\Services\MarketplaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Vendors verify the owner's own BVN before being paid. The payout account
 * itself can be in the business's name.
 */
class VendorBvnTest extends TestCase
{
    use RefreshDatabase;

    private const BVN = '22100000009';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'services.gateway.url' => 'https://gateway.test',
            'services.scapa.url' => 'https://scapa.test',
            'services.scapa.api_key' => 'test-key',
            'services.scapa.client_id' => 'CHERISHLY',
        ]);
    }

    private function fakes(string $bvnFirst = 'BOLA', string $bvnLast = 'ADEYEMI'): void
    {
        Http::fake([
            // A business account: the name is the company's, not the owner's.
            'gateway.test/api/Gateway/banks/resolve*' => Http::response(['data' => ['accountName' => 'GIFTS AND MORE LTD']]),
            'gateway.test/api/Payouts/initiate' => Http::response(['data' => ['status' => 'pending']]),
            'scapa.test/api/ScapaMiddleware/verify_bvn' => Http::response([
                'status' => true,
                'data' => [
                    'requestReference' => 'REF-V1', 'verificationStatus' => 'VERIFIED', 'watchlisted' => 'NO',
                    'personalInfo' => ['firstName' => $bvnFirst, 'lastName' => $bvnLast, 'fullName' => "$bvnFirst $bvnLast", 'dateOfBirth' => '1988-01-02'],
                    'residentialInfo' => ['stateOfResidence' => 'Lagos', 'lgaOfResidence' => 'Ikeja', 'residentialAddress' => '1 SHOP ST'],
                ],
            ]),
        ]);
    }

    private function vendor(array $extra = []): Vendor
    {
        $user = User::create([
            'first_name' => 'Bola', 'last_name' => 'Adeyemi', 'email' => 'bola@x.test', 'phone' => '08030000099',
            'password' => 'secret-pass', 'is_email_verified' => true,
        ]);
        return Vendor::create($extra + [
            'user_id' => $user->id, 'business_name' => 'Gifts and More Ltd', 'contact_name' => 'Bola',
            'phone' => '08000000000', 'email' => 'shop@x.test', 'address' => '1 Shop St', 'city' => 'Ikeja',
            'state' => 'Lagos', 'delivery_states' => ['Lagos'], 'status' => 'approved', 'approved_at' => now(),
        ]);
    }

    private function saveBank(Vendor $vendor, ?string $bvn = self::BVN)
    {
        Sanctum::actingAs($vendor->user);
        return $this->putJson('/api/vendor/bank', array_filter([
            'bank_code' => '058', 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'bvn' => $bvn,
        ]));
    }

    public function test_business_account_is_accepted_with_the_owners_bvn(): void
    {
        $this->fakes();
        $vendor = $this->vendor();

        $this->saveBank($vendor)->assertOk()
            ->assertJsonPath('data.account_name', 'GIFTS AND MORE LTD')
            ->assertJsonPath('data.identity.bvn_verified', true)
            ->assertJsonPath('data.identity.bvn_last4', '0009')
            ->assertJsonMissingPath('data.user.wallet');

        $wallet = Wallet::where('user_id', $vendor->user_id)->first();
        $this->assertTrue($wallet->bvn_verified);
        $this->assertSame(self::BVN, $wallet->bvn());
        $this->assertSame('BOLA ADEYEMI', $wallet->bvn_full_name);

        // The vendor's own screen shows the status, never the number.
        $me = $this->getJson('/api/vendor/me')->assertOk();
        $me->assertJsonPath('data.vendor.identity.bvn_verified', true);
        $this->assertStringNotContainsString(self::BVN, $me->getContent());
    }

    public function test_bvn_is_required_the_first_time(): void
    {
        $this->fakes();
        $vendor = $this->vendor();

        $this->saveBank($vendor, null)->assertStatus(422)->assertJsonPath('errors.needs_bvn', true);
        $this->assertNull($vendor->fresh()->account_number);
        Http::assertNothingSent();
    }

    public function test_a_bvn_already_verified_as_a_customer_is_reused(): void
    {
        $this->fakes();
        $vendor = $this->vendor();
        Wallet::create(['user_id' => $vendor->user_id, 'bvn_verified' => true]);

        $this->saveBank($vendor, null)->assertOk()->assertJsonPath('data.account_name', 'GIFTS AND MORE LTD');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'scapa.test'));
    }

    public function test_bvn_must_be_the_account_holders_own(): void
    {
        $this->fakes('CHIDI', 'OKAFOR');
        $vendor = $this->vendor();

        $this->saveBank($vendor)->assertStatus(422)->assertJsonPath('errors.needs_bvn', true);
        $this->assertNull($vendor->fresh()->account_number);
        $this->assertFalse((bool) Wallet::where('user_id', $vendor->user_id)->value('bvn_verified'));
    }

    public function test_payout_waits_for_bvn_then_goes_through(): void
    {
        $this->fakes();
        $vendor = $this->vendor(['bank_code' => '058', 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'GIFTS AND MORE LTD']);
        $celebrant = User::create(['first_name' => 'C', 'last_name' => 'B', 'email' => 'c@x.test', 'password' => 'secret-pass']);
        $registry = GiftRegistry::create(['user_id' => $celebrant->id, 'name' => 'Our wedding']);
        $gift = \App\Models\Gift::forceCreate(['registry_id' => $registry->id, 'name' => 'Blender', 'price' => 50000]);
        $order = VendorOrder::forceCreate([
            'reference' => 'VO-BVN1', 'vendor_id' => $vendor->id, 'gift_id' => $gift->id,
            'registry_id' => $registry->id, 'user_id' => $celebrant->id, 'item_name' => 'Blender',
            'amount' => 50000, 'commission_rate' => 10, 'commission_amount' => 5000, 'vendor_amount' => 45000,
            'status' => 'completed', 'completed_at' => now(), 'payout_status' => 'none',
        ]);

        app(MarketplaceService::class)->payVendor($order);
        $this->assertSame('failed', $order->fresh()->payout_status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'Payouts/initiate'));

        $this->saveBank($vendor)->assertOk();
        $this->assertSame('processing', $order->fresh()->payout_status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'Payouts/initiate') && $r['accountName'] === 'GIFTS AND MORE LTD');
    }
}
