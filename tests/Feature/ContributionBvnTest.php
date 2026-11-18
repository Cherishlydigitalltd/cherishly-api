<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Contribution;
use App\Models\Gift;
use App\Models\GiftRegistry;
use App\Models\MonetaryContribution;
use App\Models\MonetaryGift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Gifts above the admin's limit need a BVN that Scapa verifies and that
 * matches the giver's name. Only the last 4 digits and the BVN's name are kept.
 */
class ContributionBvnTest extends TestCase
{
    use RefreshDatabase;

    private const BVN = '22100000009';

    private int $scapaCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'array',
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
            'scapa.test/api/ScapaMiddleware/verify_bvn' => function () {
                $this->scapaCalls++;
                return Http::response([
                    'status' => true,
                    'data' => [
                        'requestReference' => 'REF-C1', 'verificationStatus' => 'VERIFIED', 'watchlisted' => 'NO',
                        'personalInfo' => ['firstName' => 'DAYO', 'middleName' => 'JOHN', 'lastName' => 'ADEBAYO', 'fullName' => 'DAYO JOHN ADEBAYO'],
                    ],
                ]);
            },
        ]);
        $this->setLimit(100000);
    }

    private function setLimit(int $limit): void
    {
        Sanctum::actingAs(Admin::create([
            'first_name' => 'A', 'last_name' => 'D', 'email' => Str::random(6) . '@admin.test',
            'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true,
        ]), [], 'sanctum');
        $this->putJson('/api/admin/settings/contribution-bvn', ['threshold' => $limit])->assertOk();
        $this->getJson('/api/admin/settings/contribution-bvn')->assertJsonPath('data.threshold', $limit);
    }

    private function owner(): User
    {
        return User::create([
            'first_name' => 'Tolu', 'last_name' => 'Bello', 'email' => Str::random(6) . '@x.test',
            'password' => 'secret-pass', 'is_email_verified' => true,
        ]);
    }

    private function cashGift(): MonetaryGift
    {
        return MonetaryGift::create([
            'user_id' => $this->owner()->id, 'title' => 'Wedding fund', 'target_amount' => 10000000,
            'share_token' => Str::random(16), 'is_active' => true,
        ]);
    }

    private function giver(array $extra = []): array
    {
        return $extra + ['donor_name' => 'Dayo Adebayo', 'donor_email' => 'dayo@x.test', 'payment_method' => 'paystack'];
    }

    public function test_small_gifts_need_no_bvn(): void
    {
        $gift = $this->cashGift();
        $this->getJson("/api/public/monetary/{$gift->share_token}")->assertJsonPath('data.bvn_threshold', 100000);

        $this->postJson("/api/public/monetary/{$gift->share_token}/contribute", $this->giver(['amount' => 100000]))
            ->assertStatus(201);
        $this->assertSame(0, $this->scapaCalls);
    }

    public function test_big_cash_gift_needs_a_real_matching_bvn(): void
    {
        $gift = $this->cashGift();
        $url = "/api/public/monetary/{$gift->share_token}/contribute";

        // No BVN: asked for one.
        $this->postJson($url, $this->giver(['amount' => 150000]))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['needs_bvn']]);

        // Not 11 digits.
        $this->postJson($url, $this->giver(['amount' => 150000, 'bvn' => 'abcdefghijk', 'bvn_first_name' => 'Dayo', 'bvn_last_name' => 'Adebayo']))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['bvn']]);

        // Someone else's BVN (name doesn't match).
        $this->postJson($url, $this->giver(['amount' => 150000, 'bvn' => self::BVN, 'bvn_first_name' => 'Kemi', 'bvn_last_name' => 'Okafor']))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['bvn']]);
        $this->assertSame(0, MonetaryContribution::count());

        // Their own BVN: goes to checkout, and only the last 4 digits are kept.
        $this->postJson($url, $this->giver(['amount' => 150000, 'bvn' => self::BVN, 'bvn_first_name' => 'Dayo', 'bvn_last_name' => 'Adebayo']))
            ->assertStatus(201)->assertJsonStructure(['data' => ['payment_url']]);

        $c = MonetaryContribution::first();
        $this->assertNull($c->getRawOriginal('bvn'));
        $this->assertSame('0009', $c->bvn_last4);
        $this->assertSame('DAYO JOHN ADEBAYO', $c->bvn_full_name);
        $this->assertSame('REF-C1', $c->bvn_reference);
        $this->assertNotNull($c->bvn_verified_at);
        $this->assertArrayNotHasKey('bvn_hash', $c->toArray());
    }

    public function test_splitting_a_big_gift_still_needs_a_bvn(): void
    {
        $gift = $this->cashGift();
        $url = "/api/public/monetary/{$gift->share_token}/contribute";

        $this->postJson($url, $this->giver(['amount' => 80000]))->assertStatus(201);
        MonetaryContribution::query()->update(['payment_status' => 'successful']);

        // Same email (any case): 80k already given + 30k is over the 100k limit.
        $this->postJson($url, $this->giver(['amount' => 30000, 'donor_email' => 'DAYO@x.test']))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['needs_bvn']]);

        // Verified once, the same BVN isn't sent to Scapa again on this gift.
        $bvn = ['bvn' => self::BVN, 'bvn_first_name' => 'Dayo', 'bvn_last_name' => 'Adebayo'];
        $this->postJson($url, $this->giver(['amount' => 30000] + $bvn))->assertStatus(201);
        $this->postJson($url, $this->giver(['amount' => 30000] + $bvn))->assertStatus(201);
        $this->assertSame(1, $this->scapaCalls);
    }

    public function test_big_registry_contribution_needs_a_bvn(): void
    {
        $registry = GiftRegistry::create([
            'user_id' => $this->owner()->id, 'name' => 'Our home', 'share_token' => Str::random(16), 'is_public' => true,
        ]);
        $gift = Gift::create(['registry_id' => $registry->id, 'name' => 'Car', 'price' => 9000000, 'quantity' => 1, 'type' => 'physical', 'allow_cash_contribution' => true]);
        $url = "/api/public/registries/{$registry->share_token}/gifts/{$gift->id}/contribute";

        $this->getJson("/api/public/registries/{$registry->share_token}")->assertJsonPath('data.bvn_threshold', 100000);
        $this->postJson($url, $this->giver(['amount' => 500000]))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['needs_bvn']]);
        $this->postJson($url, $this->giver(['amount' => 500000, 'bvn' => self::BVN, 'bvn_first_name' => 'Adebayo', 'bvn_last_name' => 'Dayo']))
            ->assertStatus(201);
        $this->assertSame('0009', Contribution::first()->bvn_last4);
    }

    public function test_limit_of_zero_turns_the_check_off(): void
    {
        $this->setLimit(0);
        $gift = $this->cashGift();
        $this->postJson("/api/public/monetary/{$gift->share_token}/contribute", $this->giver(['amount' => 6000000]))
            ->assertStatus(201);
        $this->assertSame(0, $this->scapaCalls);
    }
}
