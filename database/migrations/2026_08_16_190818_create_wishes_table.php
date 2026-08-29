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
        Schema::create('wishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wall_id')->constrained('memory_walls')->onDelete('cascade');
            $table->string('name');
            $table->text('message');
            $table->boolean('is_anonymous')->default(false);
            $table->timestamps();

            $table->index(['wall_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishes');
    }
};
