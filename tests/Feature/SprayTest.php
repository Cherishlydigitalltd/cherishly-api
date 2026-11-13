<?php

namespace Tests\Feature;

use App\Jobs\SettlePendingFunds;
use App\Models\Event;
use App\Models\Spray;
use App\Models\SprayGuest;
use App\Models\SprayTopup;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Digital spray: guests load a bundle (the host's money from that moment),
 * spray it onto the big screen, and anything unsprayed falls as a final
 * shower. Nothing is refunded.
 */
class SprayTest extends TestCase
{
    use RefreshDatabase;

    private const BVN = '22100000009';

    /** What the fake gateway says when asked about a payment. */
    private array $verifyReply = ['status' => true, 'paymentStatus' => 'pending', 'amount' => 0];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'array',
            'app.alert_email' => 'alerts@x.test',
            'app.frontend_url' => 'https://cherishlyng.test',
            'services.gateway.url' => 'https://gateway.test',
            'services.scapa.url' => 'https://scapa.test',
            'services.scapa.api_key' => 'test-key',
            'services.scapa.client_id' => 'CHERISHLY',
        ]);
        Http::fake([
            'gateway.test/api/Gateway/initialize' => fn ($request) => Http::response(['data' => [
                'paymentUrl' => 'https://checkout.test/' . $request['reference'], 'reference' => $request['reference'], 'accessCode' => 'AC',
            ]]),
            'gateway.test/api/Gateway/verify/*' => fn () => Http::response(['data' => $this->verifyReply]),
            'scapa.test/api/ScapaMiddleware/verify_bvn' => Http::response([
                'status' => true,
                'data' => [
                    'requestReference' => 'REF-S1', 'verificationStatus' => 'VERIFIED', 'watchlisted' => 'NO',
                    'personalInfo' => ['firstName' => 'DAYO', 'middleName' => 'JOHN', 'lastName' => 'ADEBAYO', 'fullName' => 'DAYO JOHN ADEBAYO'],
                ],
            ]),
        ]);
    }

    private function host(array $extra = []): User
    {
        return User::create($extra + [
            'first_name' => 'Tolu', 'last_name' => 'Bello', 'email' => 'tolu@x.test',
            'password' => 'secret-pass', 'is_email_verified' => true,
        ]);
    }

    private function sprayEvent(?User $host = null): Event
    {
        $host ??= $this->host();
        $event = Event::create(['user_id' => $host->id, 'title' => "Tolu & Femi's Wedding", 'share_token' => Str::random(16)]);
        Sanctum::actingAs($host);
        $this->putJson("/api/events/{$event->id}/spray", ['spray_enabled' => true])->assertOk();

        return $event->fresh();
    }

    private function join(Event $event, array $data = []): string
    {
        $res = $this->postJson("/api/public/spray/{$event->spray_code}/join", $data + [
            'display_name' => 'Uncle Dayo', 'email' => 'dayo@x.test', 'message' => 'Dance well o!',
        ])->assertCreated();

        return $res->json('data.token');
    }

    /** Start a top-up and have the gateway confirm it. Returns the reference. */
    private function load(Event $event, string $token, float $amount, array $extra = []): string
    {
        $ref = $this->withHeader('X-Spray-Token', $token)
            ->postJson("/api/public/spray/{$event->spray_code}/topup", ['amount' => $amount] + $extra)
            ->assertCreated()->json('data.reference');
        $this->postJson('/api/webhooks/payment/test', ['reference' => $ref, 'status' => 'successful', 'type' => 'monetary_contribution'])->assertOk();

        return $ref;
    }

    private function spray(Event $event, string $token, float $amount, int $notes = 1)
    {
        return $this->withHeader('X-Spray-Token', $token)
            ->postJson("/api/public/spray/{$event->spray_code}/spray", ['amount' => $amount, 'notes' => $notes]);
    }

    public function test_host_turns_spray_on_and_gets_a_link(): void
    {
        $event = $this->sprayEvent();

        $this->assertMatchesRegularExpression('/^[A-Z2-9]{6}$/', $event->spray_code);
        $this->getJson("/api/events/{$event->id}/spray")->assertOk()
            ->assertJsonPath('data.settings.enabled', true)
            ->assertJsonPath('data.settings.guest_url', "https://cherishlyng.test/s/{$event->spray_code}")
            ->assertJsonPath('data.settings.screen_url', "https://cherishlyng.test/s/{$event->spray_code}/screen");

        $this->getJson('/api/public/spray/' . strtolower($event->spray_code))->assertOk()
            ->assertJsonPath('data.open', true)
            ->assertJsonPath('data.min_topup', 5000)
            ->assertJsonPath('data.fee_bearer', 'gifter');

        // Another user can't see or change it.
        Sanctum::actingAs(User::create(['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@x.test', 'password' => 'secret-pass']));
        $this->getJson("/api/events/{$event->id}/spray")->assertForbidden();
        $this->putJson("/api/events/{$event->id}/spray", ['spray_enabled' => false])->assertForbidden();
    }

    public function test_a_top_up_credits_the_host_once_and_loads_the_bundle(): void
    {
        $event = $this->sprayEvent();
        $token = $this->join($event);

        $res = $this->withHeader('X-Spray-Token', $token)
            ->postJson("/api/public/spray/{$event->spray_code}/topup", ['amount' => 20000])->assertCreated();
        $ref = $res->json('data.reference');
        $this->assertStringStartsWith('CHR_SPR_', $ref);
        $res->assertJsonPath('data.gross_amount', 20500); // guest pays the 2.5% fee by default
        Http::assertSent(fn ($r) => str_contains($r->url(), 'Gateway/initialize')
            && $r['amount'] == 20500 && $r['metadata']['type'] === 'monetary_contribution'
            && $r['callbackUrl'] === "https://cherishlyng.test/s/{$event->spray_code}/paid");

        // Nothing to spray until it's paid.
        $this->spray($event, $token, 1000)->assertStatus(422);

        // The gateway's webhook, twice (they resend).
        foreach ([1, 2] as $_) {
            $this->postJson('/api/webhooks/payment/test', ['reference' => $ref, 'status' => 'successful', 'type' => 'monetary_contribution'])->assertOk();
        }

        $wallet = Wallet::where('user_id', $event->user_id)->first();
        $this->assertEquals(20000, $wallet->balance);
        $this->assertEquals(20000, $wallet->pending_settlement);
        $this->assertEquals(0, $wallet->available_balance);
        $this->assertSame(1, $wallet->transactions()->count());

        $this->withHeader('X-Spray-Token', $token)->getJson("/api/public/spray/{$event->spray_code}/me")->assertOk()
            ->assertJsonPath('data.loaded', 20000)
            ->assertJsonPath('data.balance', 20000);

        // Next day it's withdrawable, and the nightly wallet check is happy.
        $this->travel(25)->hours();
        (new SettlePendingFunds)->handle();
        $this->assertEquals(20000, $wallet->fresh()->available_balance);
        $this->artisan('wallets:check --force')->assertSuccessful();
        $checks = array_filter(app('mailer')->getSymfonyTransport()->messages()->all(),
            fn ($m) => str_contains((string) $m->getOriginalMessage()->getSubject(), 'Wallet check'));
        $this->assertCount(0, $checks);
    }

    public function test_host_pays_fee_when_they_chose_to(): void
    {
        $event = $this->sprayEvent($this->host(['fee_bearer' => 'owner']));
        $token = $this->join($event);
        $this->load($event, $token, 10000);

        $topup = SprayTopup::first();
        $this->assertEquals(10000, $topup->gross_amount);
        $this->assertEquals(9750, $topup->net_amount);
        $this->assertEquals(9750, Wallet::where('user_id', $event->user_id)->value('balance'));
        // The guest can still spray the full ₦10,000 they paid.
        $this->spray($event, $token, 10000, 10)->assertOk()->assertJsonPath('data.balance', 0);
    }

    public function test_spraying_spends_the_bundle_and_never_more(): void
    {
        $event = $this->sprayEvent();
        $token = $this->join($event);
        $this->load($event, $token, 5000);

        $this->spray($event, $token, 1000)->assertOk()->assertJsonPath('data.balance', 4000);
        $this->spray($event, $token, 3500, 5)->assertOk()->assertJsonPath('data.balance', 500);
        $this->spray($event, $token, 1000)->assertStatus(422)->assertJsonPath('errors.amount.0', 'Not enough left in your bundle. Top up to keep spraying.');
        $this->spray($event, $token, 250)->assertStatus(422); // whole notes only

        // A token from another event, or none, gets nowhere.
        $other = $this->sprayEvent($this->host(['email' => 'other@x.test']));
        $this->spray($other, $token, 200)->assertForbidden();
        $this->flushHeaders()->postJson("/api/public/spray/{$event->spray_code}/spray", ['amount' => 200, 'notes' => 1])->assertForbidden();

        $this->assertEquals(4500, SprayGuest::first()->sprayed);
    }

    public function test_big_sprayers_verify_their_bvn(): void
    {
        DB::table('settings')->where('key', 'spray_bvn_threshold')->update(['value' => '100000']);
        Cache::flush();
        $event = $this->sprayEvent();
        $token = $this->join($event);
        $this->load($event, $token, 60000);

        // ₦60k + ₦50k crosses ₦100k.
        $url = "/api/public/spray/{$event->spray_code}/topup";
        $this->withHeader('X-Spray-Token', $token)->postJson($url, ['amount' => 50000])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['needs_bvn']]);

        // A second phone with the same email doesn't get round it.
        $token2 = $this->join($event);
        $this->withHeader('X-Spray-Token', $token2)->postJson($url, ['amount' => 50000])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['needs_bvn']]);

        // Wrong name: refused.
        $this->withHeader('X-Spray-Token', $token)->postJson($url, ['amount' => 50000, 'bvn' => self::BVN, 'bvn_first_name' => 'Kemi', 'bvn_last_name' => 'Adebayo'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['bvn']]);

        // Their legal name (any order of their names): verified and the top-up starts.
        $this->withHeader('X-Spray-Token', $token)->postJson($url, ['amount' => 50000, 'bvn' => self::BVN, 'bvn_first_name' => 'Dayo', 'bvn_last_name' => 'Adebayo'])
            ->assertCreated();

        $guest = SprayGuest::where('token', $token)->first();
        $this->assertSame('0009', $guest->bvn_last4);
        $this->assertSame('DAYO JOHN ADEBAYO', $guest->bvn_full_name);
        $this->assertNotNull($guest->bvn_verified_at);
        $this->assertSame('Uncle Dayo', $guest->screenName()); // the screen still shows their chosen name

        // Below the limit nobody is asked.
        $small = $this->join($event, ['email' => 'amaka@x.test', 'display_name' => 'Amaka']);
        $this->withHeader('X-Spray-Token', $small)->postJson($url, ['amount' => 20000])->assertCreated();
    }

    public function test_big_screen_feed_hides_messages_and_anonymous_leaders(): void
    {
        $event = $this->sprayEvent();
        $dayo = $this->join($event);
        $anon = $this->join($event, ['is_anonymous' => true, 'display_name' => null, 'email' => 'a@x.test', 'message' => 'God bless']);
        $this->load($event, $dayo, 10000);
        $this->load($event, $anon, 10000);

        $screenUrl = "/api/public/spray/{$event->spray_code}/screen";
        $start = $this->getJson($screenUrl)->assertOk()->json('data');
        $this->assertSame([], $start['feed']);

        $this->spray($event, $dayo, 2000, 2)->assertOk();
        $this->spray($event, $anon, 5000, 5)->assertOk();

        $data = $this->getJson($screenUrl . '?after=' . $start['last_id'])->assertOk()->json('data');
        $this->assertCount(2, $data['feed']);
        $this->assertSame('Uncle Dayo', $data['feed'][0]['name']);
        $this->assertSame('Dance well o!', $data['feed'][0]['message']);
        $this->assertSame('Anonymous', $data['feed'][1]['name']);
        $this->assertEquals(7000, $data['total']);
        $this->assertSame(2, $data['sprayers']);
        $this->assertSame([['name' => 'Uncle Dayo', 'amount' => 2000]], $data['leaders']);

        // Host hides Dayo's message.
        $guestId = SprayGuest::where('token', $dayo)->value('id');
        $this->putJson("/api/events/{$event->id}/spray/guests/{$guestId}/message", ['hidden' => true])->assertOk();
        $again = $this->getJson($screenUrl . '?after=0')->json('data');
        $this->assertNull($again['feed'][0]['message']);

        // Opening the screen fresh shows only the latest few, not the whole night.
        foreach (range(1, 4) as $_) {
            $this->spray($event, $dayo, 200)->assertOk();
        }
        $this->assertCount(3, $this->getJson($screenUrl)->json('data.feed'));
    }

    public function test_ending_spraying_showers_the_leftovers(): void
    {
        $event = $this->sprayEvent();
        $token = $this->join($event);
        $this->load($event, $token, 10000);
        $this->spray($event, $token, 3000, 3)->assertOk();

        $this->postJson("/api/events/{$event->id}/spray/end")->assertOk()
            ->assertJsonPath('data.settings.enabled', false)
            ->assertJsonPath('data.totals.sprayed', 10000)
            ->assertJsonPath('data.totals.unsprayed', 0);

        $final = Spray::where('is_final', true)->first();
        $this->assertEquals(7000, $final->amount);
        $this->getJson("/api/public/spray/{$event->spray_code}/screen?after=0")->assertJsonPath('data.ended', true);

        // Closed now: no more top-ups or sprays.
        $this->spray($event, $token, 200)->assertStatus(422);
        $this->withHeader('X-Spray-Token', $token)->postJson("/api/public/spray/{$event->spray_code}/topup", ['amount' => 5000])->assertStatus(422);

        // The host's wallet still has all ₦10,000.
        $this->assertEquals(10000, Wallet::where('user_id', $event->user_id)->value('balance'));
    }

    public function test_a_slow_webhook_is_covered_by_asking_the_gateway(): void
    {
        $event = $this->sprayEvent();
        $token = $this->join($event);
        $ref = $this->withHeader('X-Spray-Token', $token)
            ->postJson("/api/public/spray/{$event->spray_code}/topup", ['amount' => 5000])->json('data.reference');

        $this->verifyReply = ['status' => true, 'paymentStatus' => 'success', 'amount' => 5125, 'reference' => $ref];

        $this->withHeader('X-Spray-Token', $token)->getJson("/api/public/spray/{$event->spray_code}/me")
            ->assertOk()->assertJsonPath('data.balance', 5000);
        $this->assertEquals(5000, Wallet::where('user_id', $event->user_id)->value('balance'));

        // The webhook arriving later doesn't credit it twice.
        $this->postJson('/api/webhooks/payment/test', ['reference' => $ref, 'status' => 'successful', 'type' => 'monetary_contribution'])->assertOk();
        $this->assertEquals(5000, Wallet::where('user_id', $event->user_id)->value('balance'));
    }

    public function test_join_needs_a_name_unless_anonymous_and_spray_must_be_on(): void
    {
        $event = $this->sprayEvent();
        $url = "/api/public/spray/{$event->spray_code}/join";

        $this->postJson($url, ['email' => 'a@x.test'])->assertStatus(422)->assertJsonStructure(['errors' => ['display_name']]);
        $this->postJson($url, ['email' => 'a@x.test', 'is_anonymous' => true])->assertCreated();

        $this->putJson("/api/events/{$event->id}/spray", ['spray_enabled' => false])->assertOk();
        $this->postJson($url, ['email' => 'b@x.test', 'display_name' => 'B'])->assertStatus(422);
        $this->getJson('/api/public/spray/NOPE22')->assertNotFound();
    }

    public function test_admin_sees_top_ups_and_sets_the_bvn_limit(): void
    {
        $event = $this->sprayEvent();
        $token = $this->join($event);
        $this->load($event, $token, 20000);

        Sanctum::actingAs(\App\Models\Admin::create([
            'first_name' => 'A', 'last_name' => 'D', 'email' => 'admin@x.test',
            'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true,
        ]), [], 'sanctum');

        $this->getJson('/api/admin/spray/topups?q=dayo')->assertOk()
            ->assertJsonPath('data.summary.loaded', 20000)
            ->assertJsonPath('data.summary.fees', 500)
            ->assertJsonPath('data.summary.events', 1)
            ->assertJsonPath('data.topups.data.0.guest.display_name', 'Uncle Dayo')
            ->assertJsonPath('data.topups.data.0.event.title', "Tolu & Femi's Wedding");
        $this->getJson('/api/admin/transactions?kind=spray')->assertOk();

        $this->putJson('/api/admin/settings/spray', ['bvn_threshold' => 250000])->assertOk();
        $this->getJson('/api/admin/settings/spray')->assertJsonPath('data.bvn_threshold', 250000);
    }
}
