<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class TokenExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function customerToken(): string
    {
        User::create([
            'first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'ada@x.test',
            'password' => 'secret-pass', 'is_email_verified' => true, 'is_active' => true,
        ]);
        return $this->postJson('/api/auth/login', ['email' => 'ada@x.test', 'password' => 'secret-pass'])
            ->assertOk()->json('data.token');
    }

    private function adminToken(): string
    {
        config(['auth.admin_two_factor' => false]);
        Admin::create([
            'first_name' => 'A', 'last_name' => 'D', 'email' => 'admin@x.test',
            'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true,
        ]);
        return $this->postJson('/api/admin/login', ['email' => 'admin@x.test', 'password' => 'secret-pass'])
            ->assertOk()->json('data.token');
    }

    private function hit(string $url, string $token)
    {
        app('auth')->forgetGuards();
        RateLimiter::clear(md5('api' . '127.0.0.1'));
        return $this->withToken($token)->getJson($url);
    }

    private function stored(string $token): PersonalAccessToken
    {
        return PersonalAccessToken::findToken($token);
    }

    public function test_admin_sign_in_lasts_twelve_hours(): void
    {
        $token = $this->adminToken();
        $this->assertTrue($this->stored($token)->expires_at->between(now()->addHours(11), now()->addHours(13)));

        $this->travel(11)->hours();
        $this->hit('/api/admin/me', $token)->assertOk();

        // Using it doesn't extend an admin sign-in.
        $this->travel(2)->hours();
        $this->hit('/api/admin/me', $token)->assertStatus(401);
    }

    public function test_customer_sign_in_renews_while_used_and_ends_after_thirty_idle_days(): void
    {
        $token = $this->customerToken();
        $this->assertTrue($this->stored($token)->expires_at->between(now()->addDays(29), now()->addDays(31)));

        // Used on day 20: pushed back to 30 days from then.
        $this->travel(20)->days();
        $this->hit('/api/auth/me', $token)->assertOk();
        $this->assertTrue($this->stored($token)->expires_at->greaterThan(now()->addDays(29)));

        // Day 45 overall (25 days after last use): still signed in.
        $this->travel(25)->days();
        $this->hit('/api/auth/me', $token)->assertOk();

        // Then 31 days without opening the app: signed out.
        $this->travel(31)->days();
        $this->hit('/api/auth/me', $token)->assertStatus(401);
    }

    public function test_renewal_writes_at_most_once_a_day(): void
    {
        $token = $this->customerToken();
        $first = $this->stored($token)->expires_at;

        $this->travel(2)->hours();
        $this->hit('/api/auth/me', $token)->assertOk();
        $this->assertTrue($this->stored($token)->expires_at->equalTo($first));
    }

    public function test_existing_tokens_get_an_expiry_from_the_migration(): void
    {
        $user = User::create(['first_name' => 'B', 'last_name' => 'C', 'email' => 'b@x.test', 'password' => 'secret-pass']);
        $admin = Admin::create(['first_name' => 'A', 'last_name' => 'D', 'email' => 'old@x.test', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);
        $userToken = $user->createToken('auth_token')->accessToken;
        $adminToken = $admin->createToken('admin-token')->accessToken;
        $this->assertNull($userToken->expires_at);

        (require database_path('migrations/2026_11_10_120000_give_existing_sign_in_tokens_an_expiry.php'))->up();

        $this->assertTrue($userToken->fresh()->expires_at->between(now()->addDays(29), now()->addDays(31)));
        $this->assertTrue($adminToken->fresh()->expires_at->between(now()->addHours(11), now()->addHours(13)));
    }

    public function test_expired_tokens_are_pruned(): void
    {
        $token = $this->customerToken();
        $this->travel(32)->days();

        $this->artisan('sanctum:prune-expired', ['--hours' => 24])->assertSuccessful();
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertNull(PersonalAccessToken::findToken($token));
    }
}
