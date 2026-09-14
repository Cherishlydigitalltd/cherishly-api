<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('gross_amount', 12, 2)->nullable()->after('amount');
            $table->decimal('net_amount', 12, 2)->nullable()->after('gross_amount');
            $table->decimal('fee_amount', 12, 2)->default(0)->after('net_amount');
            $table->decimal('fee_rate', 5, 2)->default(0)->after('fee_amount');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['gross_amount', 'net_amount', 'fee_amount', 'fee_rate']);
        });
    }
};