<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BvnVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const BVN = '22100000001';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gateway.url' => 'https://gateway.test',
            'services.scapa.url' => 'https://scapa.test',
            'services.scapa.api_key' => 'test-key',
            'services.scapa.client_id' => 'CHERISHLY',
        ]);
    }

    private function fakes(array $bvnData = [], bool $status = true, string $accountName = 'ADA NGOZI OBI'): void
    {
        Http::fake([
            'gateway.test/*' => Http::response(['data' => ['accountName' => $accountName, 'status' => 'pending']]),
            'scapa.test/api/ScapaMiddleware/verify_bvn' => Http::response([
                'status' => $status,
                'statusMessage' => $status ? 'BVN Verification Completed Successfully' : 'Invalid BVN',
                'data' => $status ? array_replace_recursive([
                    'requestReference' => 'REF123',
                    'verificationStatus' => 'VERIFIED',
                    'watchlisted' => 'NO',
                    'personalInfo' => [
                        'firstName' => 'NGOZI', 'middleName' => 'ADA', 'lastName' => 'OBI',
                        'fullName' => 'NGOZI ADA OBI', 'dateOfBirth' => '1995-03-14',
                    ],
                    'residentialInfo' => [
                        'stateOfResidence' => 'Lagos State', 'lgaOfResidence' => 'Ikeja',
                        'residentialAddress' => '2 HOME ROAD, IKEJA',
                    ],
                ], $bvnData) : null,
            ]),
        ]);
    }

    private function user(string $email = 'ada@x.test'): User
    {
        static $n = 0;
        $n++;
        return User::create([
            'first_name' => 'Ada', 'last_name' => 'Obi', 'email' => $email, 'phone' => '0803000000' . $n,
            'password' => 'secret-pass', 'is_email_verified' => true,
        ]);
    }

    private function saveBank(User $user, ?string $bvn = self::BVN)
    {
        Sanctum::actingAs($user);
        return $this->putJson('/api/wallet/bank-details', array_filter([
            'bank_name' => 'Access Bank', 'bank_code' => '044',
            'account_number' => '0123456789', 'account_name' => 'ADA OBI', 'bvn' => $bvn,
        ]));
    }

    public function test_verified_bvn_is_saved_encrypted_with_kyc_details(): void
    {
        $this->fakes();
        $user = $this->user();

        $this->saveBank($user)->assertOk()
            ->assertJsonPath('data.bvn_verified', true)
            ->assertJsonPath('data.bvn_last4', '0001')
            ->assertJsonMissingPath('data.bvn');

        $wallet = Wallet::where('user_id', $user->id)->first();
        $this->assertTrue($wallet->bvn_verified);
        $this->assertSame(self::BVN, Crypt::decryptString($wallet->bvn_encrypted));
        $this->assertStringNotContainsString(self::BVN, $wallet->getRawOriginal('bvn_encrypted'));
        $this->assertSame('NGOZI ADA OBI', $wallet->bvn_full_name);
        $this->assertSame('1995-03-14', $wallet->bvn_date_of_birth->format('Y-m-d'));
        $this->assertSame('2 HOME ROAD, IKEJA', $wallet->bvn_address);
        $this->assertSame('REF123', $wallet->bvn_reference);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'verify_bvn')
            && $r->hasHeader('XApiKey', 'test-key')
            && $r['bvn_number'] === self::BVN && $r['client_id'] === 'CHERISHLY');

        // Wallet summary never exposes the full BVN.
        $summary = $this->getJson('/api/wallet')->assertOk();
        $this->assertStringNotContainsString(self::BVN, $summary->getContent());
    }

    public function test_bvn_is_required_the_first_time(): void
    {
        $this->fakes();
        $this->saveBank($this->user(), null)->assertStatus(400)
            ->assertJsonPath('message', 'Please enter your BVN. We need it to verify your identity before paying money out.');
        $this->saveBank($this->user('b@x.test'), '123')->assertStatus(422);
    }

    public function test_name_on_bvn_must_match_the_user(): void
    {
        $this->fakes(['personalInfo' => ['firstName' => 'TUNDE', 'middleName' => '', 'lastName' => 'BAKARE', 'fullName' => 'TUNDE BAKARE']]);
        $user = $this->user();

        $this->saveBank($user)->assertStatus(400)
            ->assertJsonPath('message', 'The name on this BVN doesn’t match the name on your Cherishly account.');
        $this->assertFalse((bool) Wallet::where('user_id', $user->id)->value('bvn_verified'));
    }

    public function test_invalid_bvn_is_refused(): void
    {
        $this->fakes([], false);
        $this->saveBank($this->user())->assertStatus(400)
            ->assertJsonPath('message', 'We couldn’t verify that BVN. Please check the number and try again.');
    }

    public function test_watchlisted_bvn_is_refused(): void
    {
        $this->fakes(['watchlisted' => 'YES']);
        $this->saveBank($this->user('w@x.test'))->assertStatus(400)
            ->assertJsonPath('message', 'We couldn’t complete your verification. Please contact support@cherishlyng.com.');
    }

    public function test_one_bvn_cannot_verify_two_accounts(): void
    {
        $this->fakes();
        $this->saveBank($this->user())->assertOk();
        $this->saveBank($this->user('twin@x.test'))->assertStatus(400)
            ->assertJsonPath('message', 'This BVN is already linked to another Cherishly account. Please contact support@cherishlyng.com.');
    }

    public function test_changing_bank_account_later_does_not_need_the_bvn_again(): void
    {
        $this->fakes();
        $user = $this->user();
        $this->saveBank($user)->assertOk();
        Http::fake(['gateway.test/*' => Http::response(['data' => ['accountName' => 'ADA OBI']])]);

        $this->saveBank($user, null)->assertOk()->assertJsonPath('data.bvn_verified', true);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'verify_bvn'));
    }

    public function test_withdrawal_needs_a_verified_bvn(): void
    {
        $this->fakes();
        $user = $this->user();
        Wallet::create([
            'user_id' => $user->id, 'balance' => 5000, 'available_balance' => 5000,
            'bank_name' => 'Access Bank', 'bank_code' => '044', 'account_number' => '0123456789', 'account_name' => 'ADA OBI',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/wallet/withdraw', ['amount' => 2000])->assertStatus(400)
            ->assertJsonPath('errors.needs_bvn', true);
        $this->assertEquals(5000, Wallet::where('user_id', $user->id)->value('available_balance'));

        $this->saveBank($user)->assertOk();
        Sanctum::actingAs($user);
        $this->postJson('/api/wallet/withdraw', ['amount' => 2000])->assertOk();
    }

    public function test_not_configured_fails_safely(): void
    {
        config(['services.scapa.api_key' => null]);
        $this->fakes();
        $this->saveBank($this->user())->assertStatus(400)
            ->assertJsonPath('message', 'BVN verification is temporarily unavailable. Please try again later.');
    }
}
