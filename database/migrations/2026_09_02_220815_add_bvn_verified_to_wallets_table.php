<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->boolean('bvn_verified')->default(false)->after('bank_code');
            $table->text('bvn_encrypted')->nullable()->after('bvn_verified');
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn(['bvn_verified', 'bvn_encrypted']);
        });
    }
};
