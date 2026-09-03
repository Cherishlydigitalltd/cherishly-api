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
        Schema::table('memory_walls', function (Blueprint $table) {
            $table->string('wall_type', 20)->default('wishes')->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('memory_walls', function (Blueprint $table) {
            $table->dropColumn('wall_type');
        });
    }
};
