<?php

namespace Tests\Feature;

use App\Models\AsoebiOrder;
use App\Models\Event;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Aso-ebi: the coordinator lists packages, buyers pay through the shop link,
 * the money lands in the coordinator's wallet, and each order is handed over once.
 */
class AsoebiTest extends TestCase
{
    use RefreshDatabase;

    private User $host;
    private Event $event;
    private array $verifyReply = ['status' => true, 'paymentStatus' => 'pending', 'amount' => 0];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'array',
            'app.frontend_url' => 'https://cherishlyng.test',
            'services.gateway.url' => 'https://gateway.test',
        ]);
        Http::fake([
            'gateway.test/api/Gateway/initialize' => fn ($request) => Http::response(['data' => [
                'paymentUrl' => 'https://checkout.test/' . $request['reference'], 'reference' => $request['reference'], 'accessCode' => 'AC',
            ]]),
            'gateway.test/api/Gateway/verify/*' => fn () => Http::response(['data' => $this->verifyReply]),
        ]);
        $this->host = User::create([
            'first_name' => 'Tolu', 'last_name' => 'Bello', 'email' => 'tolu@x.test',
            'password' => 'secret-pass', 'is_email_verified' => true,
        ]);
        $this->event = Event::create(['user_id' => $this->host->id, 'title' => "Tolu & Femi's Wedding", 'share_token' => Str::random(16)]);
    }

    private function asHost(): void
    {
        Sanctum::actingAs($this->host);
    }

    /** Coordinator adds a package and opens the shop; returns [code, package id]. */
    private function openShop(array $package = [], array $settings = []): array
    {
        $this->asHost();
        $url = "/api/events/{$this->event->id}/asoebi";
        $res = $this->postJson("$url/packages", $package + ['name' => 'Ladies: lace + gele', 'price' => 65000])->assertCreated();
        $id = $res->json('data.packages.0.id');
        $code = $this->putJson($url, $settings + ['enabled' => true])->assertOk()->json('data.settings.shop_url');

        return [basename($code), $id];
    }

    private function order(string $code, int $packageId, array $extra = []): AsoebiOrder
    {
        $token = $this->postJson("/api/public/asoebi/{$code}/orders", $extra + [
            'package_id' => $packageId, 'quantity' => 1, 'buyer_name' => 'Adaeze Okafor', 'email' => 'ada@x.test',
        ])->assertCreated()->json('data.order_token');

        return AsoebiOrder::where('pickup_token', $token)->first();
    }

    private function pay(AsoebiOrder $order): void
    {
        $this->postJson('/api/webhooks/payment/test', ['reference' => $order->payment_reference, 'status' => 'successful', 'type' => 'monetary_contribution'])->assertOk();
    }

    public function test_shop_needs_a_package_and_opens_with_a_link(): void
    {
        $this->asHost();
        $this->putJson("/api/events/{$this->event->id}/asoebi", ['enabled' => true])->assertStatus(422);

        [$code] = $this->openShop([], ['colours' => 'Wine & gold', 'delivery_fee' => 3500, 'pickup_note' => 'Ikeja, from 5 Dec']);
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $code);

        $this->getJson("/api/public/asoebi/{$code}")->assertOk()
            ->assertJsonPath('data.open', true)
            ->assertJsonPath('data.colours', 'Wine & gold')
            ->assertJsonPath('data.delivery_fee', 3500)
            ->assertJsonPath('data.packages.0.name', 'Ladies: lace + gele')
            ->assertJsonPath('data.packages.0.price', 65000);

        // The event page's aso-ebi button now points at the shop.
        $this->event->update(['slug' => 'tolu-femi']);
        $this->getJson('/api/public/e/tolu-femi')->assertOk()->assertJsonPath('data.aso_ebi.url', "https://cherishlyng.test/a/{$code}");
    }

    public function test_paying_credits_the_coordinator_once_and_emails_the_buyer(): void
    {
        [$code, $pkg] = $this->openShop();
        $order = $this->order($code, $pkg, ['quantity' => 2]);
        $this->assertSame('pending', $order->payment_status);
        $this->assertEquals(130000, $order->amount);
        $this->assertSame('AE-1001', $order->order_no);
        $this->assertStringContainsString('aso-ebi\\/order\\/' . $order->pickup_token, json_encode(Http::recorded()[0][0]->data()));

        $this->pay($order);
        $this->pay($order); // the gateway resends

        $wallet = Wallet::where('user_id', $this->host->id)->first();
        $this->assertEquals(130000, $wallet->balance);
        $this->assertEquals(1, $wallet->transactions()->count());
        $this->assertStringContainsString('Aso-ebi AE-1001', $wallet->transactions()->first()->description);

        $this->getJson('/api/public/asoebi-orders/' . $order->pickup_token)->assertOk()
            ->assertJsonPath('data.payment_status', 'successful')
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.package', 'Ladies: lace + gele');
    }

    public function test_a_slow_webhook_is_covered_by_asking_the_gateway(): void
    {
        [$code, $pkg] = $this->openShop();
        $order = $this->order($code, $pkg);
        $this->verifyReply = ['status' => true, 'paymentStatus' => 'success', 'amount' => (float) $order->gross_amount];

        $this->getJson('/api/public/asoebi-orders/' . $order->pickup_token)->assertJsonPath('data.payment_status', 'successful');
        $this->pay($order);
        $this->assertEquals(65000, Wallet::where('user_id', $this->host->id)->value('balance'));
    }

    public function test_stock_runs_out_and_unpaid_orders_hold_it_briefly(): void
    {
        [$code, $pkg] = $this->openShop(['stock' => 3]);
        $first = $this->order($code, $pkg, ['quantity' => 2]);

        $this->postJson("/api/public/asoebi/{$code}/orders", ['package_id' => $pkg, 'quantity' => 2, 'buyer_name' => 'B', 'email' => 'b@x.test'])
            ->assertStatus(422)->assertJsonPath('errors.quantity.0', 'Only 1 left of this package.');

        // An abandoned payment stops holding stock after 30 minutes.
        $first->forceFill(['created_at' => now()->subHour()])->save();
        $this->getJson("/api/public/asoebi/{$code}")->assertJsonPath('data.packages.0.left', 3);
    }

    public function test_delivery_adds_the_fee_and_needs_an_address(): void
    {
        [$code, $pkg] = $this->openShop([], ['delivery_fee' => 3500]);
        $this->postJson("/api/public/asoebi/{$code}/orders", ['package_id' => $pkg, 'quantity' => 1, 'buyer_name' => 'A', 'email' => 'a@x.test', 'delivery' => true])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['delivery_address']]);

        $order = $this->order($code, $pkg, ['delivery' => true, 'delivery_address' => '5 Admiralty Way, Lekki']);
        $this->assertEquals(68500, $order->amount);
        $this->assertTrue($order->delivery);
    }

    public function test_orders_close_after_the_deadline(): void
    {
        [$code, $pkg] = $this->openShop([], ['deadline' => now()->subDay()->toDateTimeString()]);
        $this->getJson("/api/public/asoebi/{$code}")->assertJsonPath('data.open', false);
        $this->postJson("/api/public/asoebi/{$code}/orders", ['package_id' => $pkg, 'quantity' => 1, 'buyer_name' => 'A', 'email' => 'a@x.test'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['closed']]);
    }

    public function test_helpers_hand_each_paid_order_over_once(): void
    {
        [$code, $pkg] = $this->openShop();
        $paid = $this->order($code, $pkg);
        $this->pay($paid);
        $unpaid = $this->order($code, $pkg, ['buyer_name' => 'Kunle Bakare', 'email' => 'kunle@x.test']);

        $this->asHost();
        $handout = basename($this->putJson("/api/events/{$this->event->id}/asoebi", ['handout_enabled' => true])->json('data.settings.handout_url'));

        $this->postJson("/api/public/asoebi-handout/{$handout}/scan", ['scanned' => 'https://cherishlyng.com/aso-ebi/order/' . $paid->pickup_token])->assertOk()
            ->assertJsonPath('data.order.buyer_name', 'Adaeze Okafor')
            ->assertJsonPath('data.order.handed_over_at', null);

        $give = "/api/public/asoebi-handout/{$handout}/orders/{$paid->pickup_token}/hand-over";
        $this->withHeader('X-Usher-Name', 'Chioma')->postJson($give)->assertOk()
            ->assertJsonPath('data.result', 'handed_over')
            ->assertJsonPath('data.order.handed_over_by', 'Chioma')
            ->assertJsonPath('data.stats.handed_over', 1);
        $this->withHeader('X-Usher-Name', 'Bayo')->postJson($give)->assertJsonPath('data.result', 'already')
            ->assertJsonPath('data.order.handed_over_by', 'Chioma');

        $this->postJson("/api/public/asoebi-handout/{$handout}/orders/{$unpaid->pickup_token}/hand-over")->assertJsonPath('data.result', 'unpaid');
        $this->getJson("/api/public/asoebi-handout/{$handout}/search?q=ada")->assertJsonCount(1, 'data.orders');
        $this->getJson("/api/public/asoebi-handout/{$handout}/search?q=kunle")->assertJsonCount(0, 'data.orders'); // unpaid hidden

        // Switching the link off stops it.
        $this->asHost();
        $this->putJson("/api/events/{$this->event->id}/asoebi", ['handout_enabled' => false])->assertOk();
        $this->getJson("/api/public/asoebi-handout/{$handout}")->assertNotFound();
    }

    public function test_coordinator_sees_stats_reminds_unpaid_and_exports(): void
    {
        Mail::fake();
        [$code, $pkg] = $this->openShop();
        $this->pay($this->order($code, $pkg, ['quantity' => 2]));
        $stale = $this->order($code, $pkg, ['buyer_name' => 'Kunle Bakare', 'email' => 'kunle@x.test']);
        $stale->forceFill(['created_at' => now()->subHour()])->save();

        $this->asHost();
        $this->getJson("/api/events/{$this->event->id}/asoebi")->assertOk()
            ->assertJsonPath('data.stats.orders', 1)
            ->assertJsonPath('data.stats.units', 2)
            ->assertJsonPath('data.stats.money', 130000)
            ->assertJsonPath('data.stats.unpaid', 1)
            ->assertJsonPath('data.packages.0.sold', 2)
            ->assertJsonPath('data.orders.data.0.buyer_name', 'Adaeze Okafor');
        $this->getJson("/api/events/{$this->event->id}/asoebi?filter=unpaid")->assertJsonPath('data.orders.data.0.buyer_name', 'Kunle Bakare');

        $this->postJson("/api/events/{$this->event->id}/asoebi/remind")->assertOk()->assertJsonPath('data.sent', 1);
        $this->postJson("/api/events/{$this->event->id}/asoebi/remind")->assertJsonPath('data.sent', 0); // once a day

        $csv = $this->get("/api/events/{$this->event->id}/asoebi/export")->assertOk()->streamedContent();
        $this->assertStringContainsString('AE-1001', $csv);
        $this->assertStringNotContainsString('Kunle', $csv);
    }

    public function test_a_package_with_orders_is_hidden_not_deleted(): void
    {
        [$code, $pkg] = $this->openShop();
        $this->order($code, $pkg);
        $this->asHost();
        $this->deleteJson("/api/events/{$this->event->id}/asoebi/packages/{$pkg}")->assertOk()
            ->assertJsonPath('data.packages.0.is_active', false);
        $this->getJson("/api/public/asoebi/{$code}")->assertJsonCount(0, 'data.packages');
    }

    public function test_other_users_cant_run_someone_elses_aso_ebi(): void
    {
        Sanctum::actingAs(User::create([
            'first_name' => 'Ola', 'last_name' => 'X', 'email' => 'ola@x.test', 'password' => 'secret-pass', 'is_email_verified' => true,
        ]));
        $this->getJson("/api/events/{$this->event->id}/asoebi")->assertForbidden();
        $this->postJson("/api/events/{$this->event->id}/asoebi/packages", ['name' => 'X', 'price' => 1000])->assertForbidden();
        $this->getJson('/api/public/asoebi/ABCDEFGH')->assertNotFound();
    }
}
