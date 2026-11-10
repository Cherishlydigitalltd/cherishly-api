<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tokens issued before sign-ins had a lifetime never expire. Give them one
 * from today: customers get the normal rolling 30 days (renewed while they
 * use the app), admins 12 hours, after which they sign in again.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('personal_access_tokens')
            ->whereNull('expires_at')
            ->where('tokenable_type', 'App\Models\Admin')
            ->update(['expires_at' => now()->addHours(12)]);

        DB::table('personal_access_tokens')
            ->whereNull('expires_at')
            ->where('tokenable_type', 'App\Models\User')
            ->update(['expires_at' => now()->addDays(30)]);
    }

    public function down(): void
    {
        // Nothing to undo: expiry dates on old tokens are harmless.
    }
};
