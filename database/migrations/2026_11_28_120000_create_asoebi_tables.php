<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aso-ebi: a coordinator sells the event's fabric packages through one link
 * (/a/<code>); buyers pay, get a pickup code, and helpers hand the fabric
 * over once per order through a handout link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $t) {
            $t->boolean('asoebi_enabled')->default(false);
            $t->string('asoebi_code', 12)->nullable()->unique();      // buyers: /a/<code>
            $t->timestamp('asoebi_deadline')->nullable();
            $t->string('asoebi_colours', 120)->nullable();
            $t->text('asoebi_pickup_note')->nullable();               // where and when to collect
            $t->decimal('asoebi_delivery_fee', 12, 2)->nullable();    // null: pickup only
            $t->boolean('asoebi_handout_enabled')->default(false);
            $t->string('asoebi_handout_code', 12)->nullable()->unique(); // helpers: /a-handout/<code>
        });

        Schema::create('asoebi_packages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('description', 200)->nullable();
            $t->decimal('price', 12, 2);
            $t->unsignedInteger('stock')->nullable();                  // null: no limit
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('asoebi_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->cascadeOnDelete();
            $t->foreignId('asoebi_package_id')->constrained()->restrictOnDelete();
            $t->string('order_no', 12)->index();                       // AE-1042, per event
            $t->string('buyer_name', 80);
            $t->string('email', 150);
            $t->string('phone', 30)->nullable();
            $t->unsignedSmallInteger('quantity');
            $t->decimal('unit_price', 12, 2);
            $t->boolean('delivery')->default(false);
            $t->string('delivery_address', 300)->nullable();
            $t->decimal('delivery_fee', 12, 2)->default(0);
            $t->decimal('amount', 12, 2);                              // packages + delivery
            $t->decimal('gross_amount', 12, 2);
            $t->decimal('net_amount', 12, 2);
            $t->decimal('fee_amount', 12, 2)->default(0);
            $t->decimal('fee_rate', 5, 2)->default(0);
            $t->string('fee_bearer', 10)->default('gifter');
            $t->string('payment_reference', 80)->unique();
            $t->string('payment_status', 20)->default('pending');      // pending | successful | failed
            $t->json('payment_meta')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->string('pickup_token', 40)->unique();
            $t->timestamp('handed_over_at')->nullable();
            $t->string('handed_over_by', 60)->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->timestamps();
            $t->index(['event_id', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asoebi_orders');
        Schema::dropIfExists('asoebi_packages');
        Schema::table('events', function (Blueprint $t) {
            $t->dropUnique(['asoebi_code']);
            $t->dropUnique(['asoebi_handout_code']);
            $t->dropColumn(['asoebi_enabled', 'asoebi_code', 'asoebi_deadline', 'asoebi_colours', 'asoebi_pickup_note',
                'asoebi_delivery_fee', 'asoebi_handout_enabled', 'asoebi_handout_code']);
        });
    }
};
