<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BVN verification (KYC) for payouts. The BVN itself stays in the existing
 * encrypted column; these hold the verified identity and the audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            // Keyed hash of the BVN, so one BVN can't verify several accounts
            // (the encrypted value can't be searched).
            $table->string('bvn_hash', 64)->nullable()->index()->after('bvn_encrypted');
            $table->string('bvn_full_name')->nullable()->after('bvn_hash');
            $table->date('bvn_date_of_birth')->nullable()->after('bvn_full_name');
            $table->string('bvn_address', 500)->nullable()->after('bvn_date_of_birth');
            $table->string('bvn_state', 100)->nullable()->after('bvn_address');
            $table->string('bvn_lga', 100)->nullable()->after('bvn_state');
            $table->string('bvn_reference', 100)->nullable()->after('bvn_lga');
            $table->timestamp('bvn_verified_at')->nullable()->after('bvn_reference');
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn(['bvn_hash', 'bvn_full_name', 'bvn_date_of_birth', 'bvn_address',
                'bvn_state', 'bvn_lga', 'bvn_reference', 'bvn_verified_at']);
        });
    }
};
