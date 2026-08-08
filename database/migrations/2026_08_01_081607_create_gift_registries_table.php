<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_registries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('cover_photo')->nullable();
            $table->boolean('is_public')->default(true);
            $table->string('share_token')->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_public']);
            $table->index('share_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_registries');
    }
};
