<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Gift;
use App\Models\GiftRegistry;
use App\Models\Invitation;
use App\Models\MemoryWall;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorOrder;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.assets.url' => 'https://assets.test']);
        Http::fake(['assets.test/*' => Http::response(['status' => true])]);
    }

    private function user(array $extra = []): User
    {
        return User::create($extra + [
            'first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'ada@x.test', 'phone' => '08030000000',
            'password' => 'secret-pass', 'is_email_verified' => true, 'is_active' => true,
            'avatar' => 'https://assets.test/avatars/ada.jpg',
        ]);
    }

    private function wallet(User $user, float $balance = 0, float $available = 0): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id, 'balance' => $balance, 'available_balance' => $available,
            'pending_settlement' => $balance - $available,
            'bank_name' => 'Access Bank', 'bank_code' => '044', 'account_number' => '0123456789', 'account_name' => 'ADA OBI',
        ]);
    }

    private function admin(): Admin
    {
        return Admin::firstOrCreate(['email' => 'admin@x.test'], [
            'first_name' => 'A', 'last_name' => 'D', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true,
        ]);
    }

    /** The user asks; an admin then carries it out. */
    private function deleteAs(User $user, string $password = 'secret-pass')
    {
        Sanctum::actingAs($user);
        $res = $this->postJson('/api/profile/delete-request', ['password' => $password]);
        if ($res->status() !== 200) {
            return $res;
        }
        app('auth')->forgetGuards();
        Sanctum::actingAs($this->admin());
        $id = $res->json('data.request.id');
        return $this->putJson("/api/admin/deletion-requests/{$id}/complete");
    }

    public function test_deletes_personal_data_and_keeps_anonymised_payment_records(): void
    {
        $user = $this->user();
        $wallet = $this->wallet($user);
        DB::table('wallets')->where('id', $wallet->id)->update(['bvn_encrypted' => encrypt('22222222222'), 'bvn_verified' => true]);
        $tx = $wallet->transactions()->create([
            'user_id' => $user->id, 'type' => 'credit', 'amount' => 5000, 'description' => 'Gift', 'reference' => 'CHR_GIFT_1_x', 'status' => 'successful',
        ]);

        $registry = GiftRegistry::create(['user_id' => $user->id, 'name' => 'Our wedding']);
        $invitation = Invitation::forceCreate(['user_id' => $user->id, 'title' => 'Wedding', 'share_token' => 'inv-token']);
        $invitation->guests()->create(['full_name' => 'Guest One', 'email' => 'g1@x.test']);
        $wall = MemoryWall::forceCreate(['user_id' => $user->id, 'title' => 'Wishes', 'share_token' => 'wall-token', 'is_active' => true]);
        $wall->wishes()->create(['name' => 'Funmi', 'message' => 'Congratulations!']);
        $user->createToken('auth_token');

        $this->deleteAs($user)->assertOk()->assertJsonPath('message', 'Account deleted.');

        $row = User::withTrashed()->find($user->id);
        $this->assertNotNull($row->deleted_at);
        $this->assertSame('Deleted', $row->first_name);
        $this->assertStringEndsWith('@deleted.cherishlyng.com', $row->email);
        $this->assertNull($row->phone);
        $this->assertNull($row->avatar);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());

        $wallet->refresh();
        $this->assertNull($wallet->account_number);
        $this->assertNull(DB::table('wallets')->where('id', $wallet->id)->value('bvn_encrypted'));

        // Payment record kept.
        $this->assertDatabaseHas('transactions', ['id' => $tx->id, 'amount' => 5000]);

        // Content hidden, guest data removed.
        $this->assertSoftDeleted('gift_registries', ['id' => $registry->id]);
        $this->assertSoftDeleted('invitations', ['id' => $invitation->id]);
        $this->assertSame(0, DB::table('invitation_guests')->count());
        $this->assertSame(0, DB::table('wishes')->count());
        $this->assertNull(app(\App\Services\MemoryWallService::class)->findByToken('wall-token'));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'assets.test/delete'));

        // The email is free again.
        $this->assertTrue(User::create([
            'first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'ada@x.test', 'password' => 'another-pass',
        ])->exists);
    }

    public function test_wrong_password_deletes_nothing(): void
    {
        $user = $this->user();
        $this->deleteAs($user, 'wrong-pass')->assertStatus(422)->assertJsonPath('message', 'Your password is incorrect.');
        $this->assertNull($user->fresh()->deleted_at);
    }

    public function test_money_in_the_wallet_blocks_deletion(): void
    {
        $user = $this->user();
        $this->wallet($user, 3000, 1000);

        $this->deleteAs($user)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'still settling'));
        $this->assertSame('Ada', $user->fresh()->first_name);
    }

    public function test_pending_withdrawal_blocks_deletion(): void
    {
        $user = $this->user();
        $wallet = $this->wallet($user);
        $wallet->transactions()->create([
            'user_id' => $user->id, 'type' => 'debit', 'amount' => 2000, 'description' => 'Withdrawal', 'reference' => 'WD-1', 'status' => 'pending',
        ]);

        $this->deleteAs($user)->assertStatus(422)->assertJsonPath('message', 'Can’t delete yet: A withdrawal is still being processed. Try again once it has gone through.');
    }

    public function test_open_vendor_order_blocks_deletion(): void
    {
        $user = $this->user();
        $registry = GiftRegistry::create(['user_id' => $user->id, 'name' => 'Our wedding']);
        $vendorUser = User::create(['first_name' => 'V', 'last_name' => 'D', 'email' => 'v@x.test', 'password' => 'secret-pass']);
        $vendor = Vendor::create([
            'user_id' => $vendorUser->id, 'business_name' => 'Gifts Ltd', 'contact_name' => 'Bola', 'phone' => '0800',
            'email' => 'shop@x.test', 'address' => '1 Shop St', 'city' => 'Ikeja', 'state' => 'Lagos',
            'delivery_states' => ['Lagos'], 'status' => 'approved',
        ]);
        $gift = Gift::forceCreate(['registry_id' => $registry->id, 'name' => 'Blender', 'price' => 50000, 'fulfilment' => 'order']);
        VendorOrder::forceCreate([
            'reference' => 'VO-1', 'vendor_id' => $vendor->id, 'gift_id' => $gift->id, 'registry_id' => $registry->id,
            'user_id' => $user->id, 'item_name' => 'Blender', 'amount' => 50000, 'commission_rate' => 10,
            'commission_amount' => 5000, 'vendor_amount' => 45000, 'status' => 'dispatched',
        ]);

        $this->deleteAs($user)->assertStatus(422);

        // The vendor can't be deleted either while the order is open.
        $this->assertStringContainsString('orders that aren’t finished', app(\App\Services\AccountDeletionService::class)->blockers($vendorUser)[0]);
    }

    public function test_deleted_user_cannot_sign_in(): void
    {
        $user = $this->user();
        $this->deleteAs($user)->assertOk();
        app('auth')->forgetGuards();

        $this->postJson('/api/auth/login', ['email' => 'ada@x.test', 'password' => 'secret-pass'])->assertStatus(401);
    }

    public function test_admin_delete_uses_the_same_safe_process(): void
    {
        $user = $this->user();
        $this->wallet($user, 500, 500);

        Sanctum::actingAs($this->admin());
        $this->deleteJson("/api/admin/users/{$user->id}")->assertStatus(422);
        $this->assertNull($user->fresh()->deleted_at);

        Wallet::where('user_id', $user->id)->update(['balance' => 0, 'available_balance' => 0, 'pending_settlement' => 0]);
        $this->deleteJson("/api/admin/users/{$user->id}")->assertOk();
        $this->assertSame('Deleted', User::withTrashed()->find($user->id)->first_name);
    }

    public function test_submitting_a_request_deletes_nothing(): void
    {
        $user = $this->user();
        $this->wallet($user, 5000, 5000); // even with money, the request is accepted
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/delete-request', ['password' => 'secret-pass', 'reason' => 'Not using it'])
            ->assertOk()->assertJsonPath('data.request.status', 'pending');
        $this->postJson('/api/profile/delete-request', ['password' => 'secret-pass'])->assertOk(); // no duplicate

        $this->assertSame(1, \App\Models\AccountDeletionRequest::count());
        $this->assertNull($user->fresh()->deleted_at);
        $this->assertSame('Ada', $user->fresh()->first_name);
        $this->getJson('/api/profile/delete-request')->assertJsonPath('data.request.status', 'pending');

        // The user can still sign in and change their mind.
        $this->deleteJson('/api/profile/delete-request')->assertOk();
        $this->getJson('/api/profile/delete-request')->assertJsonPath('data.request', null);
        $this->assertSame('cancelled', \App\Models\AccountDeletionRequest::first()->status);
    }

    public function test_admin_sees_requests_with_blockers_and_can_reject(): void
    {
        $user = $this->user();
        $this->wallet($user, 2000, 2000);
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/profile/delete-request', ['password' => 'secret-pass', 'reason' => 'Moving abroad'])->json('data.request.id');

        // Customers can't see the admin list.
        $this->getJson('/api/admin/deletion-requests')->assertStatus(401);

        app('auth')->forgetGuards();
        Sanctum::actingAs($this->admin());
        $res = $this->getJson('/api/admin/deletion-requests?status=pending')->assertOk();
        $this->assertSame(1, $res->json('data.pending_count'));
        $this->assertSame('ada@x.test', $res->json('data.page.data.0.email'));
        $this->assertSame('Moving abroad', $res->json('data.page.data.0.reason'));
        $this->assertStringContainsString('₦2,000.00', $res->json('data.page.data.0.blockers.0'));

        $this->putJson("/api/admin/deletion-requests/{$id}/reject", [])->assertStatus(422); // note required
        $this->putJson("/api/admin/deletion-requests/{$id}/reject", ['note' => 'Withdraw your balance first'])->assertOk();
        $this->assertSame('rejected', \App\Models\AccountDeletionRequest::find($id)->status);
        $this->assertNull($user->fresh()->deleted_at);
        $this->putJson("/api/admin/deletion-requests/{$id}/complete")->assertStatus(422); // already dealt with
    }
}
