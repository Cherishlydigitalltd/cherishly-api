<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor marketplace: vendors list gift items, celebrants add them to their
 * registries, guests pay for them, and the vendor delivers. Money for a vendor
 * item is held until the celebrant confirms delivery, then paid to the vendor
 * minus Cherishly's commission.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A vendor is a business profile on an ordinary Cherishly account.
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('business_name');
            $table->string('contact_name');
            $table->string('phone', 30);
            $table->string('email');
            $table->string('address');
            $table->string('city', 100);
            $table->string('state', 50);
            $table->string('cac_number', 50)->nullable();
            $table->string('logo')->nullable();
            $table->text('description')->nullable();
            $table->json('delivery_states');
            $table->string('bank_code', 20)->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->string('account_number', 20)->nullable();
            $table->string('account_name')->nullable();
            // pending → approved | rejected; approved ↔ suspended
            $table->string('status', 20)->default('pending')->index();
            $table->string('status_reason', 500)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('vendor_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('category', 100)->nullable()->index();
            $table->decimal('price', 12, 2);
            $table->json('images');
            $table->unsignedInteger('stock')->nullable(); // null = no limit
            // pending → approved | rejected. An approved item stays live while
            // an edit waits in pending_changes.
            $table->string('status', 20)->default('pending')->index();
            $table->json('pending_changes')->nullable();
            $table->string('status_reason', 500)->nullable();
            $table->boolean('is_active')->default(true); // vendor's pause switch
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('gift_registries', function (Blueprint $table) {
            // Where vendor items in this registry get delivered.
            $table->string('delivery_name')->nullable();
            $table->string('delivery_phone', 30)->nullable();
            $table->string('delivery_address', 500)->nullable();
            $table->string('delivery_state', 50)->nullable();
        });

        Schema::table('gifts', function (Blueprint $table) {
            $table->foreignId('vendor_product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            // null while collecting; 'order' once sent to the vendor; 'cash' once
            // the celebrant took the money instead.
            $table->string('fulfilment', 10)->nullable();
        });

        Schema::create('vendor_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('vendor_id')->constrained();
            $table->foreignId('gift_id')->constrained();
            $table->foreignId('registry_id')->constrained('gift_registries');
            $table->foreignId('user_id')->constrained(); // the celebrant
            $table->foreignId('vendor_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_name', 200);
            $table->string('item_image')->nullable();
            $table->decimal('amount', 12, 2);
            $table->decimal('commission_rate', 5, 2);
            $table->decimal('commission_amount', 12, 2);
            $table->decimal('vendor_amount', 12, 2);
            // awaiting_address → placed → accepted → dispatched → delivered → completed
            // or cancelled (money to the celebrant) / disputed (admin decides)
            $table->string('status', 20)->index();
            $table->string('delivery_name')->nullable();
            $table->string('delivery_phone', 30)->nullable();
            $table->string('delivery_address', 500)->nullable();
            $table->string('delivery_state', 50)->nullable();
            $table->string('vendor_note', 500)->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->string('cancelled_by', 10)->nullable(); // celebrant | vendor | admin
            $table->string('dispute_reason', 1000)->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('auto_complete_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            // none → processing → paid | failed
            $table->string('payout_status', 20)->default('none')->index();
            $table->string('payout_reference', 40)->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        DB::table('settings')->insertOrIgnore([
            'key' => 'vendor_commission_rate',
            'value' => '10',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_orders');

        Schema::table('gifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_product_id');
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropColumn('fulfilment');
        });

        Schema::table('gift_registries', function (Blueprint $table) {
            $table->dropColumn(['delivery_name', 'delivery_phone', 'delivery_address', 'delivery_state']);
        });

        Schema::dropIfExists('vendor_products');
        Schema::dropIfExists('vendors');

        DB::table('settings')->where('key', 'vendor_commission_rate')->delete();
    }
};
