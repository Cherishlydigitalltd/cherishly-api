<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Contribution;
use App\Models\Gift;
use App\Models\GiftRegistry;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorOrder;
use App\Models\VendorProduct;
use App\Services\GiftRegistryService;
use App\Services\MarketplaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The vendor marketplace end to end: vendor sign-up and approval, items,
 * payments held for the vendor, orders, delivery, payouts and refunds.
 * Runs against PostgreSQL (it uses ilike and JSON queries).
 */
class VendorMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['services.gateway.url' => 'https://gateway.test', 'services.assets.url' => 'https://assets.test']);
        Http::fake([
            'assets.test/upload' => fn () => Http::response(['status' => true, 'data' => ['url' => 'https://assets.test/p/' . Str::random(6) . '.jpg']]),
            'gateway.test/api/Gateway/initialize' => Http::response(['data' => ['paymentUrl' => 'https://pay.test/x', 'reference' => 'r', 'accessCode' => 'a']]),
            'gateway.test/api/Gateway/banks/resolve*' => Http::response(['data' => ['accountName' => 'GIFTS LTD']]),
            'gateway.test/api/Payouts/initiate' => Http::response(['data' => ['status' => 'pending']]),
        ]);
    }

    /* ── helpers ── */

    private function user(array $extra = []): User
    {
        $this->n++;
        return User::create($extra + [
            'first_name' => "User{$this->n}", 'last_name' => 'Test', 'email' => "u{$this->n}@x.test",
            'password' => 'secret-pass', 'is_email_verified' => true,
        ]);
    }

    private function admin(): Admin
    {
        return Admin::create(['first_name' => 'A', 'last_name' => 'D', 'email' => 'admin@x.test', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);
    }

    private function vendorProfile(array $extra = []): array
    {
        return $extra + [
            'business_name' => 'Gifts Ltd', 'contact_name' => 'Bola', 'phone' => '08000000000',
            'email' => 'shop@x.test', 'address' => '1 Shop St', 'city' => 'Ikeja', 'state' => 'Lagos',
            'delivery_states' => ['Lagos', 'Ogun'],
        ];
    }

    /** An approved vendor with bank details and one approved item. */
    private function liveProduct(array $product = []): VendorProduct
    {
        $user = $this->user();
        $vendor = Vendor::create($this->vendorProfile() + [
            'user_id' => $user->id, 'status' => 'approved', 'approved_at' => now(),
            'bank_code' => '058', 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'GIFTS LTD',
        ]);
        return $vendor->products()->create($product + [
            'name' => 'Blender', 'category' => 'Kitchen', 'price' => 50000,
            'images' => ['https://assets.test/p/blender.jpg'], 'status' => 'approved', 'stock' => 3,
        ]);
    }

    private function registryWithVendorGift(VendorProduct $product, array $registry = []): array
    {
        $owner = $this->user();
        $reg = GiftRegistry::create($registry + ['user_id' => $owner->id, 'name' => 'Our wedding']);
        Sanctum::actingAs($owner);
        $res = $this->postJson("/api/registries/{$reg->id}/gifts", ['vendor_product_id' => $product->id, 'price' => 1])
            ->assertCreated();
        return [$owner, $reg, Gift::find($res->json('data.id'))];
    }

    /** A guest pays $amount toward the gift and the payment goes through. */
    private function pay(Gift $gift, float $amount): Contribution
    {
        $result = app(GiftRegistryService::class)->contribute($gift->fresh(), [
            'donor_name' => 'Guest', 'donor_email' => 'g@x.test', 'amount' => $amount, 'payment_method' => 'paystack',
        ]);
        app(GiftRegistryService::class)->confirmPayment($result['reference'], 'successful');
        return Contribution::where('payment_reference', $result['reference'])->first();
    }

    private function balance(User $user): float
    {
        return (float) ($user->fresh()->wallet?->balance ?? 0);
    }

    /* ── tests ── */

    public function test_vendor_signs_up_lists_an_item_and_it_reaches_the_catalog_after_approval(): void
    {
        $vendorUser = $this->user();
        Sanctum::actingAs($vendorUser);

        $this->postJson('/api/vendor/products', [])->assertForbidden(); // not a vendor yet
        $this->postJson('/api/vendor/apply', $this->vendorProfile())->assertCreated()
            ->assertJsonPath('data.status', 'pending');
        $this->postJson('/api/vendor/apply', $this->vendorProfile())->assertStatus(422);

        $productId = $this->post('/api/vendor/products', [
            'name' => 'Toaster', 'category' => 'Kitchen', 'price' => 30000,
            'images' => [UploadedFile::fake()->image('a.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $celebrant = $this->user();
        Sanctum::actingAs($celebrant);
        $this->getJson('/api/gifts/catalog')->assertJsonCount(0, 'data.data');

        Sanctum::actingAs($this->admin());
        $vendor = $vendorUser->fresh()->vendor;
        $this->putJson("/api/admin/vendors/{$vendor->id}/status", ['status' => 'approved'])->assertOk();
        $this->putJson("/api/admin/vendor-products/{$productId}/review", ['decision' => 'approve'])->assertOk();

        Sanctum::actingAs($celebrant);
        $this->getJson('/api/gifts/catalog')->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.vendor.business_name', 'Gifts Ltd')
            ->assertJsonMissingPath('data.data.0.vendor.account_number');
        $this->getJson('/api/gifts/catalog?state=Lagos')->assertJsonCount(1, 'data.data');
        $this->getJson('/api/gifts/catalog?state=Kano')->assertJsonCount(0, 'data.data');
    }

    public function test_edits_to_a_live_item_wait_for_approval(): void
    {
        $product = $this->liveProduct();
        Sanctum::actingAs($product->vendor->user);

        $this->post("/api/vendor/products/{$product->id}", [
            'name' => 'Blender', 'category' => 'Kitchen', 'price' => 65000, 'stock' => 10,
            'keep_images' => $product->images,
        ], ['Accept' => 'application/json'])->assertOk();

        $product->refresh();
        $this->assertSame('50000.00', $product->price); // still live at the old price
        $this->assertSame(10, $product->stock);          // stock applies at once
        $this->assertEquals(65000, $product->pending_changes['price']);

        Sanctum::actingAs($this->admin());
        $this->putJson("/api/admin/vendor-products/{$product->id}/review", ['decision' => 'approve'])->assertOk();
        $this->assertSame('65000.00', $product->fresh()->price);
        $this->assertNull($product->fresh()->pending_changes);
    }

    public function test_gift_uses_the_vendor_price_and_payments_are_held_until_fully_paid(): void
    {
        $product = $this->liveProduct();
        [$owner, $reg, $gift] = $this->registryWithVendorGift($product);

        $this->assertSame('50000.00', $gift->price); // not the 1 the client sent
        $this->assertTrue($gift->is_vendor_item);

        // Can't pay more than what's left.
        $this->expectExceptionOnContribute($gift, 60000);

        $this->pay($gift, 20000);
        $this->assertSame(0.0, $this->balance($owner)); // held, not in the wallet
        $this->assertNull($gift->fresh()->vendorOrder);

        $this->pay($gift, 30000);
        $gift->refresh();
        $this->assertSame('order', $gift->fulfilment);
        $order = $gift->vendorOrder;
        $this->assertSame('awaiting_address', $order->status);
        $this->assertSame('45000.00', $order->vendor_amount); // 10% commission
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(0.0, $this->balance($owner));
    }

    public function test_full_delivery_flow_pays_the_vendor_after_confirmation(): void
    {
        $product = $this->liveProduct();
        [$owner, $reg, $gift] = $this->registryWithVendorGift($product);
        $this->pay($gift, 50000);
        $order = $gift->fresh()->vendorOrder;
        $base = "/api/registries/{$reg->id}/gifts/{$gift->id}";

        // The vendor can't see it until there's an address.
        Sanctum::actingAs($product->vendor->user);
        $this->getJson("/api/vendor/orders/{$order->id}")->assertNotFound();

        Sanctum::actingAs($owner);
        $this->getJson("/api/registries/{$reg->id}")->assertOk()
            ->assertJsonPath('data.gifts.0.vendor_order.status', 'awaiting_address')
            ->assertJsonPath('data.gifts.0.vendor.business_name', 'Gifts Ltd');
        $this->getJson("/api/registries/{$reg->id}/gifts")->assertOk()
            ->assertJsonPath('data.0.vendor_order.status', 'awaiting_address');
        $this->getJson("$base/order")->assertOk()->assertJsonPath('data.order.status', 'awaiting_address')
            ->assertJsonMissingPath('data.order.vendor_amount');
        $address = ['delivery_name' => 'Ada', 'delivery_phone' => '0801', 'delivery_address' => '2 Home Rd'];
        $this->putJson("$base/order/address", $address + ['delivery_state' => 'Kano'])->assertStatus(422);
        $this->putJson("$base/order/address", $address + ['delivery_state' => 'Lagos'])->assertOk()
            ->assertJsonPath('data.status', 'placed');
        $this->assertSame('Lagos', $reg->fresh()->delivery_state); // remembered

        Sanctum::actingAs($product->vendor->user);
        $this->postJson("/api/vendor/orders/{$order->id}/dispatch")->assertStatus(422); // accept first
        $this->postJson("/api/vendor/orders/{$order->id}/accept")->assertOk();
        $this->postJson("/api/vendor/orders/{$order->id}/dispatch", ['note' => 'Rider: 0802'])->assertOk();
        $this->postJson("/api/vendor/orders/{$order->id}/delivered")->assertOk();

        Sanctum::actingAs($owner);
        $this->postJson("$base/order/received")->assertOk();

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertSame('processing', $order->payout_status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'Payouts/initiate') && $r['amount'] == 45000
            && $r['accountNumber'] === '0123456789');

        // The payout webhook (twice, as gateways resend).
        $this->postWebhook($order->payout_reference);
        $this->postWebhook($order->payout_reference);
        $this->assertSame('paid', $order->fresh()->payout_status);
        $this->assertSame(0.0, $this->balance($owner));
    }

    public function test_second_order_in_the_registry_uses_the_saved_address(): void
    {
        $product = $this->liveProduct();
        [$owner, $reg, $gift] = $this->registryWithVendorGift($product, [
            'delivery_name' => 'Ada', 'delivery_phone' => '0801', 'delivery_address' => '2 Home Rd', 'delivery_state' => 'Ogun',
        ]);
        $this->pay($gift, 50000);
        $this->assertSame('placed', $gift->fresh()->vendorOrder->status);
    }

    public function test_celebrant_can_take_part_payment_as_cash(): void
    {
        $product = $this->liveProduct();
        [$owner, $reg, $gift] = $this->registryWithVendorGift($product);
        $this->pay($gift, 20000);

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/registries/{$reg->id}/gifts/{$gift->id}")->assertStatus(422); // money on it
        $this->postJson("/api/registries/{$reg->id}/gifts/{$gift->id}/take-cash")->assertOk();

        $this->assertSame(20000.0, $this->balance($owner));
        $this->assertSame('cash', $gift->fresh()->fulfilment);
        $this->postJson("/api/registries/{$reg->id}/gifts/{$gift->id}/take-cash")->assertStatus(422); // only once

        // A guest paying after that: the money still reaches the celebrant.
        $this->expectExceptionOnContribute($gift, 1000);
    }

    public function test_payment_that_arrives_after_the_order_goes_to_the_wallet(): void
    {
        $product = $this->liveProduct();
        [$owner, $reg, $gift] = $this->registryWithVendorGift($product);

        // Two guests start paying the full remaining amount at the same time.
        $svc = app(GiftRegistryService::class);
        $guest = ['donor_name' => 'G', 'donor_email' => 'g@x.test', 'payment_method' => 'paystack'];
        $a = $svc->contribute($gift->fresh(), $guest + ['amount' => 50000]);
        $b = $svc->contribute($gift->fresh(), $guest + ['amount' => 50000]);
        $svc->confirmPayment($a['reference'], 'successful');
        $svc->confirmPayment($b['reference'], 'successful');

        $this->assertSame(1, VendorOrder::count());
        $this->assertSame(50000.0, $this->balance($owner));
        $this->assertSame('50000.00', $gift->fresh()->amount_contributed);
    }

    public function test_the_last_few_naira_can_be_paid(): void
    {
        $product = $this->liveProduct();
        [$owner, $reg, $gift] = $this->registryWithVendorGift($product);
        $this->pay($gift, 49950);
        $this->expectExceptionOnContribute($gift, 20);  // under ₦100 and not the rest
        $this->pay($gift, 50);                           // exactly the rest: allowed
        $this->assertSame('order', $gift->fresh()->fulfilment);
    }

    public function test_vendor_declining_refunds_the_celebrant_and_restores_stock(): void
    {
        $product = $this->liveProduct();
        [$owner, $reg, $gift] = $this->registryWithVendorGift($product, [
            'delivery_name' => 'Ada', 'delivery_phone' => '0801', 'delivery_address' => '2 Home Rd', 'delivery_state' => 'Lagos',
        ]);
        $this->pay($gift, 50000);
        $order = $gift->fresh()->vendorOrder;

        Sanctum::actingAs($product->vendor->user);
        $this->postJson("/api/vendor/orders/{$order->id}/decline", ['reason' => 'Out of stock'])->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('vendor', $order->fresh()->cancelled_by);
        $this->assertSame(50000.0, $this->balance($owner));
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertSame('none', $order->fresh()->payout_status);
    }

    public function test_reported_problem_holds_payout_until_admin_decides(): void
    {
        $product = $this->liveProduct();
        [$owner, $reg, $gift] = $this->registryWithVendorGift($product, [
            'delivery_name' => 'Ada', 'delivery_phone' => '0801', 'delivery_address' => '2 Home Rd', 'delivery_state' => 'Lagos',
        ]);
        $this->pay($gift, 50000);
        $order = $gift->fresh()->vendorOrder;
        $svc = app(MarketplaceService::class);
        $svc->accept($order);
        $svc->markDelivered($order);

        Sanctum::actingAs($owner);
        $this->postJson("/api/registries/{$reg->id}/gifts/{$gift->id}/order/problem", ['reason' => 'It came broken'])->assertOk();

        // Doesn't auto-complete while disputed.
        $this->travel(4)->days();
        $svc->autoComplete();
        $this->assertSame('disputed', $order->fresh()->status);

        Sanctum::actingAs($this->admin());
        $this->putJson("/api/admin/vendor-orders/{$order->id}/resolve", ['outcome' => 'celebrant'])->assertOk();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(50000.0, $this->balance($owner));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'Payouts/initiate'));
    }

    public function test_delivered_orders_complete_automatically_after_three_days(): void
    {
        $product = $this->liveProduct();
        [$owner, $reg, $gift] = $this->registryWithVendorGift($product, [
            'delivery_name' => 'Ada', 'delivery_phone' => '0801', 'delivery_address' => '2 Home Rd', 'delivery_state' => 'Lagos',
        ]);
        $this->pay($gift, 50000);
        $order = $gift->fresh()->vendorOrder;
        $svc = app(MarketplaceService::class);
        $svc->accept($order);
        $svc->markDelivered($order);

        $this->travel(2)->days();
        $this->assertSame(0, $svc->autoComplete());
        $this->travel(2)->days();
        $this->assertSame(1, $svc->autoComplete());
        $this->assertSame('processing', $order->fresh()->payout_status);
    }

    public function test_payout_waits_for_bank_details_then_goes_through(): void
    {
        $product = $this->liveProduct();
        $product->vendor->update(['account_number' => null, 'bank_code' => null]);
        [$owner, $reg, $gift] = $this->registryWithVendorGift($product, [
            'delivery_name' => 'Ada', 'delivery_phone' => '0801', 'delivery_address' => '2 Home Rd', 'delivery_state' => 'Lagos',
        ]);
        $this->pay($gift, 50000);
        $order = $gift->fresh()->vendorOrder;
        $svc = app(MarketplaceService::class);
        $svc->accept($order);
        $svc->confirmReceived($svc->markDelivered($order));
        $this->assertSame('failed', $order->fresh()->payout_status);

        Sanctum::actingAs($product->vendor->user);
        $this->putJson('/api/vendor/bank', ['bank_code' => '058', 'bank_name' => 'GTBank', 'account_number' => '0123456789'])->assertOk();
        $this->assertSame('processing', $order->fresh()->payout_status);
    }

    public function test_access_rules(): void
    {
        $product = $this->liveProduct();
        [$owner, $reg, $gift] = $this->registryWithVendorGift($product, [
            'delivery_name' => 'Ada', 'delivery_phone' => '0801', 'delivery_address' => '2 Home Rd', 'delivery_state' => 'Lagos',
        ]);
        $this->pay($gift, 50000);
        $order = $gift->fresh()->vendorOrder;

        // Admin tokens can't use celebrant routes.
        Sanctum::actingAs($this->admin());
        $this->getJson('/api/registries')->assertUnauthorized();

        // Another vendor can't see or touch this order or item.
        $other = $this->user();
        Vendor::create($this->vendorProfile() + ['user_id' => $other->id, 'status' => 'approved']);
        Sanctum::actingAs($other);
        $this->getJson("/api/vendor/orders/{$order->id}")->assertNotFound();
        $this->postJson("/api/vendor/orders/{$order->id}/accept")->assertNotFound();
        $this->deleteJson("/api/vendor/products/{$product->id}")->assertNotFound();

        // A suspended vendor can't act on orders.
        $product->vendor->update(['status' => 'suspended']);
        Sanctum::actingAs($product->vendor->user);
        $this->postJson("/api/vendor/orders/{$order->id}/accept")->assertForbidden();

        // Another celebrant can't see the order.
        Sanctum::actingAs($this->user());
        $this->getJson("/api/registries/{$reg->id}/gifts/{$gift->id}/order")->assertForbidden();
    }

    private function expectExceptionOnContribute(Gift $gift, float $amount): void
    {
        try {
            app(GiftRegistryService::class)->contribute($gift->fresh(), [
                'donor_name' => 'G', 'donor_email' => 'g@x.test', 'amount' => $amount, 'payment_method' => 'paystack',
            ]);
            $this->fail('Contribution should have been refused.');
        } catch (\RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    private function postWebhook(string $reference): void
    {
        $this->assertTrue(app(MarketplaceService::class)->confirmPayout($reference, 'successful'));
    }
}
