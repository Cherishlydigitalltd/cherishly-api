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
        Schema::table('secret_santas', function (Blueprint $table) {
            $table->string('share_token', 20)->nullable()->unique()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('secret_santas', function (Blueprint $table) {
            $table->dropColumn('share_token');
        });
    }
};
