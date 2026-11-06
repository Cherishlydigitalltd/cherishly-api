<?php

namespace Tests\Feature;

use App\Models\Admin;
use Database\Seeders\AdminSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $email, string $role = 'admin', string $password = 'first-password'): Admin
    {
        return Admin::create(['first_name' => 'A', 'last_name' => 'D', 'email' => $email, 'password' => $password, 'role' => $role, 'is_active' => true]);
    }

    private function login(string $email, string $password = 'first-password'): string
    {
        app('auth')->forgetGuards();

        return $this->postJson('/api/admin/login', compact('email', 'password'))->assertOk()->json('data.token');
    }

    private function as(string $token)
    {
        app('auth')->forgetGuards();

        return $this->withToken($token);
    }

    public function test_changing_password_needs_the_current_one_and_signs_out_other_devices(): void
    {
        $this->admin('a@x.test');
        $here = $this->login('a@x.test');
        $other = $this->login('a@x.test');

        $this->as($here)->putJson('/api/admin/password', ['current_password' => 'wrong-password', 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password'])->assertStatus(422);
        $this->as($here)->putJson('/api/admin/password', ['current_password' => 'first-password', 'password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422);
        $this->as($here)->putJson('/api/admin/password', ['current_password' => 'first-password', 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password'])->assertOk();

        $this->as($here)->getJson('/api/admin/me')->assertOk();
        $this->as($other)->getJson('/api/admin/me')->assertStatus(401);
        app('auth')->forgetGuards();
        $this->postJson('/api/admin/login', ['email' => 'a@x.test', 'password' => 'first-password'])->assertStatus(401);
        $this->login('A@x.test', 'brand-new-password');
    }

    public function test_only_super_admins_manage_accounts(): void
    {
        $this->admin('a@x.test');
        $token = $this->login('a@x.test');

        $this->as($token)->getJson('/api/admin/admins')->assertStatus(403);
        $this->as($token)->postJson('/api/admin/admins', ['first_name' => 'N', 'last_name' => 'W', 'email' => 'n@x.test', 'password' => 'long-enough-pass', 'role' => 'super_admin'])->assertStatus(403);
        $this->assertDatabaseMissing('admins', ['email' => 'n@x.test']);
    }

    public function test_super_admin_adds_deactivates_and_resets_an_admin(): void
    {
        $this->admin('boss@x.test', 'super_admin');
        $boss = $this->login('boss@x.test');

        $this->as($boss)->postJson('/api/admin/admins', ['first_name' => 'N', 'last_name' => 'W', 'email' => 'New@x.test', 'password' => 'first-password', 'role' => 'admin'])
            ->assertStatus(201)->assertJsonMissingPath('data.password');
        $this->as($boss)->postJson('/api/admin/admins', ['first_name' => 'N', 'last_name' => 'W', 'email' => 'new@x.test', 'password' => 'first-password', 'role' => 'admin'])->assertStatus(422);
        $new = Admin::where('email', 'new@x.test')->firstOrFail();
        $this->as($boss)->getJson('/api/admin/admins')->assertOk()->assertJsonCount(2, 'data');

        $token = $this->login('new@x.test');
        $this->as($boss)->putJson("/api/admin/admins/{$new->id}/password", ['password' => 'reset-password-1'])->assertOk();
        $this->as($token)->getJson('/api/admin/me')->assertStatus(401);

        $token = $this->login('new@x.test', 'reset-password-1');
        $this->as($boss)->putJson("/api/admin/admins/{$new->id}/status", ['is_active' => false])->assertOk();
        $this->as($token)->getJson('/api/admin/me')->assertStatus(401);
        app('auth')->forgetGuards();
        $this->postJson('/api/admin/login', ['email' => 'new@x.test', 'password' => 'reset-password-1'])->assertStatus(403);

        $this->as($boss)->putJson("/api/admin/admins/{$new->id}/status", ['is_active' => true])->assertOk();
        $this->login('new@x.test', 'reset-password-1');
    }

    public function test_you_cannot_lock_yourself_out(): void
    {
        $boss = $this->admin('boss@x.test', 'super_admin');
        $token = $this->login('boss@x.test');

        $this->as($token)->putJson("/api/admin/admins/{$boss->id}/status", ['is_active' => false])->assertStatus(422);
        $this->as($token)->putJson("/api/admin/admins/{$boss->id}/password", ['password' => 'reset-password-1'])->assertStatus(422);
        $this->assertTrue($boss->fresh()->is_active);
        $this->assertTrue(Hash::check('first-password', $boss->fresh()->password));
    }

    public function test_seeders_never_overwrite_existing_values(): void
    {
        $admin = $this->admin('admin@cherishlyng.com', 'super_admin', 'my-own-password');
        DB::table('settings')->where('key', 'transaction_fee_rate')->delete();
        DB::table('settings')->insert(['key' => 'transaction_fee_rate', 'value' => '4']);

        $this->seed(AdminSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->assertTrue(Hash::check('my-own-password', $admin->fresh()->password));
        $this->assertSame('4', DB::table('settings')->where('key', 'transaction_fee_rate')->value('value'));
        $this->assertSame(1, Admin::count());
    }

    public function test_admin_seeder_creates_the_first_admin_with_no_known_password(): void
    {
        $this->seed(AdminSeeder::class);

        $admin = Admin::where('email', 'admin@cherishlyng.com')->firstOrFail();
        $this->assertTrue($admin->isSuperAdmin());
    }
}
