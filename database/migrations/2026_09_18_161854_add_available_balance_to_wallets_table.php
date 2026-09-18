<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->decimal('available_balance', 12, 2)->default(0)->after('balance');
            $table->decimal('total_withdrawn', 12, 2)->default(0)->after('total_received');
            $table->decimal('pending_settlement', 12, 2)->default(0)->after('available_balance');
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn(['available_balance', 'pending_settlement', 'total_withdrawn']);
        });
    }
};