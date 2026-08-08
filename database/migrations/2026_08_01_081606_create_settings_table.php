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
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        DB::table('settings')->insert([
            ['key' => 'sms_enabled', 'value' => 'false', 'description' => 'Enable/disable SMS OTP'],
            ['key' => 'sms_provider_1', 'value' => 'termii', 'description' => 'Primary SMS provider'],
            ['key' => 'sms_provider_2', 'value' => 'infobip', 'description' => 'Fallback SMS provider'],
            ['key' => 'otp_expiry_minutes', 'value' => '10', 'description' => 'OTP expiry in minutes'],
            ['key' => 'otp_max_attempts', 'value' => '3', 'description' => 'Max OTP attempts before lockout'],
            ['key' => 'otp_lockout_minutes', 'value' => '15', 'description' => 'OTP lockout duration in minutes'],
            ['key' => 'min_withdrawal', 'value' => '1000', 'description' => 'Minimum withdrawal amount in kobo'],
            ['key' => 'asset_base_url', 'value' => 'https://asset.cherishlyng.com', 'description' => 'Asset server base URL'],
            ['key' => 'gateway_url', 'value' => '', 'description' => '.NET Core payment gateway URL'],
            ['key' => 'gateway_api_key', 'value' => '', 'description' => 'Payment gateway API key'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
