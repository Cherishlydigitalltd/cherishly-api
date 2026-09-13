<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('transaction_fee_rate', 5, 2)->nullable()->after('avatar'); // null = use global rate
            $table->string('fee_bearer')->default('gifter')->after('transaction_fee_rate'); // gifter | owner
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['transaction_fee_rate', 'fee_bearer']);
        });
    }
};