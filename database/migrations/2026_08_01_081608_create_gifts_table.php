<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registry_id')->constrained('gift_registries')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 15, 2)->default(0);
            $table->integer('quantity')->default(1);
            $table->string('category')->nullable();
            $table->string('image')->nullable();
            $table->enum('type', ['physical', 'monetary'])->default('physical');
            $table->boolean('allow_cash_contribution')->default(false);
            $table->decimal('amount_contributed', 15, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('registry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gifts');
    }
};
