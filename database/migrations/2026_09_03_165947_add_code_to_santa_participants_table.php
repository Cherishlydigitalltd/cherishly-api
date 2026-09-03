<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('santa_participants', function (Blueprint $table) {
            $table->string('code', 8)->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('santa_participants', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
