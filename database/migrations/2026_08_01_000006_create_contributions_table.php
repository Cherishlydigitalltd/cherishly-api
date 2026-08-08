<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gift_id')->constrained('gifts')->onDelete('cascade');
            $table->string('donor_name');
            $table->string('donor_email');
            $table->string('donor_phone')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('bvn')->nullable();
            $table->enum('payment_method', ['paystack', 'bank_transfer']);
            $table->string('payment_reference')->nullable()->unique();
            $table->enum('payment_status', ['pending', 'successful', 'failed'])->default('pending');
            $table->boolean('is_anonymous')->default(false);
            $table->json('payment_meta')->nullable();
            $table->timestamps();

            $table->index(['gift_id', 'payment_status']);
            $table->index('payment_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contributions');
    }
};
