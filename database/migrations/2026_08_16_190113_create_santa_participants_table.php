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
        Schema::create('santa_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('santa_id')->constrained('secret_santas')->onDelete('cascade');
            $table->string('name');
            $table->string('email')->nullable();
            $table->foreignId('assigned_to_id')->nullable()->constrained('santa_participants')->nullOnDelete();
            $table->timestamps();

            $table->index('santa_id');
            $table->index('assigned_to_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('santa_participants');
    }
};
