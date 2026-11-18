<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Large contributions: record a verified BVN check (never the BVN itself)
 * and make the limit an admin setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['contributions', 'monetary_contributions'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('bvn_hash', 64)->nullable()->index();
                $t->string('bvn_last4', 4)->nullable();
                $t->string('bvn_full_name')->nullable();
                $t->string('bvn_reference')->nullable();
                $t->timestamp('bvn_verified_at')->nullable();
            });
        }

        DB::table('settings')->insertOrIgnore([
            'key' => 'contribution_bvn_threshold',
            'value' => '5000000',
            'description' => 'Contributions above this amount (naira, per giver per gift) need a verified BVN. 0 turns the check off.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        foreach (['contributions', 'monetary_contributions'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['bvn_hash']);
                $t->dropColumn(['bvn_hash', 'bvn_last4', 'bvn_full_name', 'bvn_reference', 'bvn_verified_at']);
            });
        }
        DB::table('settings')->where('key', 'contribution_bvn_threshold')->delete();
    }
};
