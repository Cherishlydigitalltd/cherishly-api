<?php

namespace Tests\Feature;

use App\Mail\AdminLoginCode;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AdminTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.admin_two_factor' => true]);
        Mail::fake();
        Admin::create([
            'first_name' => 'Ada', 'last_name' => 'Admin', 'email' => 'ada@x.test',
            'password' => 'first-password', 'role' => 'admin', 'is_active' => true,
        ]);
    }

    private function startLogin(): string
    {
        RateLimiter::clear(md5('login' . '127.0.0.1'));
        return $this->postJson('/api/admin/login', ['email' => 'ada@x.test', 'password' => 'first-password'])
            ->assertOk()
            ->assertJsonPath('data.two_factor', true)
            ->assertJsonMissingPath('data.token')
            ->json('data.challenge');
    }

    private function lastCode(): string
    {
        $code = null;
        Mail::assertSent(AdminLoginCode::class, function (AdminLoginCode $m) use (&$code) {
            $code = $m->code;
            return $m->hasTo('ada@x.test');
        });
        return $code;
    }

    private function verify(string $challenge, string $code)
    {
        RateLimiter::clear(md5('login' . '127.0.0.1'));
        return $this->postJson('/api/admin/login/verify', compact('challenge', 'code'));
    }

    public function test_password_then_emailed_code_signs_in(): void
    {
        $challenge = $this->startLogin();
        $token = $this->verify($challenge, $this->lastCode())->assertOk()->json('data.token');

        $this->withToken($token)->getJson('/api/admin/me')->assertOk()->assertJsonPath('data.email', 'ada@x.test');

        // A code works once only.
        $this->verify($challenge, $this->lastCode())->assertStatus(422);
    }

    public function test_wrong_password_sends_no_code(): void
    {
        $this->postJson('/api/admin/login', ['email' => 'ada@x.test', 'password' => 'wrong'])->assertStatus(401);
        Mail::assertNothingSent();
    }

    public function test_wrong_codes_are_limited_then_the_sign_in_is_cancelled(): void
    {
        $challenge = $this->startLogin();
        $right = $this->lastCode();
        $wrong = $right === '111111' ? '222222' : '111111';

        $this->verify($challenge, $wrong)->assertStatus(422)->assertJsonPath('message', 'That code isn’t right. 4 tries left.');
        for ($i = 0; $i < 3; $i++) {
            $this->verify($challenge, $wrong)->assertStatus(422);
        }
        $this->verify($challenge, $wrong)->assertStatus(422)->assertJsonPath('message', 'Too many wrong codes. Please sign in again.');

        // Even the right code no longer works.
        $this->verify($challenge, $right)->assertStatus(422);
    }

    public function test_code_expires_after_ten_minutes(): void
    {
        $challenge = $this->startLogin();
        $code = $this->lastCode();

        $this->travel(11)->minutes();
        $this->verify($challenge, $code)->assertStatus(422)->assertJsonPath('message', 'This code has expired. Please sign in again.');
    }

    public function test_resend_replaces_the_code_and_is_limited(): void
    {
        $challenge = $this->startLogin();
        $first = $this->lastCode();

        RateLimiter::clear(md5('login' . '127.0.0.1'));
        $this->postJson('/api/admin/login/resend', ['challenge' => $challenge])->assertOk();
        Mail::assertSent(AdminLoginCode::class, 2);

        $codes = [];
        Mail::assertSent(AdminLoginCode::class, function ($m) use (&$codes) { $codes[] = $m->code; return true; });
        $second = end($codes);

        if ($first !== $second) {
            $this->verify($challenge, $first)->assertStatus(422); // old code replaced
        }

        RateLimiter::clear(md5('login' . '127.0.0.1'));
        $this->postJson('/api/admin/login/resend', ['challenge' => $challenge])->assertOk();
        RateLimiter::clear(md5('login' . '127.0.0.1'));
        $this->postJson('/api/admin/login/resend', ['challenge' => $challenge])->assertStatus(429);

        Mail::assertSent(AdminLoginCode::class, 3);
    }

    public function test_suspended_between_steps_cannot_finish(): void
    {
        $challenge = $this->startLogin();
        Admin::where('email', 'ada@x.test')->update(['is_active' => false]);

        $this->verify($challenge, $this->lastCode())->assertStatus(403);
    }

    public function test_made_up_challenge_is_rejected(): void
    {
        $this->verify('not-a-real-challenge', '123456')->assertStatus(422);
    }

    public function test_switched_off_signs_in_with_password_only(): void
    {
        config(['auth.admin_two_factor' => false]);

        $this->postJson('/api/admin/login', ['email' => 'ada@x.test', 'password' => 'first-password'])
            ->assertOk()->assertJsonStructure(['data' => ['token', 'admin']]);
        Mail::assertNothingSent();
    }
}
