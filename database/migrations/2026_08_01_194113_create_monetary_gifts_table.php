<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monetary_gifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('target_amount', 15, 2)->default(0);
            $table->decimal('amount_raised', 15, 2)->default(0);
            $table->string('cover_photo')->nullable();
            $table->string('share_token')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_active']);
            $table->index('share_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monetary_gifts');
    }
};
