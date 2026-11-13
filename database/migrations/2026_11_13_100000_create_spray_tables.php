<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Digital spray: guests load a bundle (paid to the host straight away) and
 * spray it note by note onto the venue's big screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('spray_enabled')->default(false);
            $table->string('spray_code', 12)->nullable()->unique();
            $table->boolean('spray_show_total')->default(true);
            $table->boolean('spray_show_leaders')->default(true);
            $table->timestamp('spray_ended_at')->nullable();
        });

        // One per guest per event, tied to the guest's phone by a secret token.
        Schema::create('spray_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('display_name', 60)->nullable();
            $table->boolean('is_anonymous')->default(false);
            $table->string('message', 80)->nullable();
            $table->boolean('message_hidden')->default(false);
            $table->string('email');
            // What they've paid for (face value) and what they've sprayed of it.
            $table->decimal('loaded', 14, 2)->default(0);
            $table->decimal('sprayed', 14, 2)->default(0);
            // Only for guests who load more than the admin's BVN limit.
            $table->string('bvn_hash', 64)->nullable()->index();
            $table->string('bvn_last4', 4)->nullable();
            $table->string('bvn_full_name')->nullable();
            $table->string('bvn_reference', 100)->nullable();
            $table->timestamp('bvn_verified_at')->nullable();
            $table->timestamps();
            $table->index(['event_id', 'email']);
        });

        Schema::create('spray_topups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spray_guest_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);        // bundle face value (what can be sprayed)
            $table->decimal('gross_amount', 14, 2);  // what the guest paid
            $table->decimal('net_amount', 14, 2);    // what the host receives
            $table->decimal('fee_amount', 14, 2)->default(0);
            $table->decimal('fee_rate', 5, 2)->default(0);
            $table->string('fee_bearer', 20)->nullable();
            $table->string('payment_reference')->unique();
            $table->string('payment_status', 20)->default('pending');
            $table->json('payment_meta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['event_id', 'payment_status']);
        });

        Schema::create('sprays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spray_guest_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->unsignedInteger('notes')->default(1);
            $table->boolean('is_final')->default(false); // the end-of-party "final shower"
            $table->timestamps();
            $table->index(['event_id', 'id']);
        });

        DB::table('settings')->updateOrInsert(
            ['key' => 'spray_bvn_threshold'],
            ['value' => '1000000', 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sprays');
        Schema::dropIfExists('spray_topups');
        Schema::dropIfExists('spray_guests');
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique(['spray_code']);
            $table->dropColumn(['spray_enabled', 'spray_code', 'spray_show_total', 'spray_show_leaders', 'spray_ended_at']);
        });
        DB::table('settings')->where('key', 'spray_bvn_threshold')->delete();
    }
};
