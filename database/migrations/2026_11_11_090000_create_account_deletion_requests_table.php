<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users ask for their account to be deleted; an admin reviews and carries
 * it out (or rejects it). Nothing is deleted when the request is made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            // Copied at request time, so the admin list still makes sense
            // after the account has been anonymised.
            $table->string('name');
            $table->string('email');
            $table->string('reason', 1000)->nullable();
            $table->string('source', 20)->default('app'); // app | web
            // pending → completed | rejected, or cancelled by the user
            $table->string('status', 20)->default('pending')->index();
            $table->string('admin_note', 1000)->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_deletion_requests');
    }
};
