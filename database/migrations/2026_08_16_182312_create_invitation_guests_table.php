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
        Schema::create('invitation_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invitation_id')->constrained()->onDelete('cascade');
            $table->string('full_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->enum('rsvp_status', ['pending', 'attending', 'not_attending'])->default('pending');
            $table->boolean('allow_plus_one')->default(false);
            $table->boolean('has_plus_one')->default(false);
            $table->boolean('checked_in')->default(false);
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('rsvp_responded_at')->nullable();
            $table->string('qr_token')->unique()->nullable();
            $table->timestamps();

            $table->index(['invitation_id', 'rsvp_status']);
            $table->index('qr_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_guests');
    }
};
