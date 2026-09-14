<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            [
                'key' => 'transaction_fee_rate',
                'value' => '2.5',
                'description' => 'Platform transaction fee percentage applied to all contributions',
                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'key' => 'withdrawal_fee_rate',
                'value' => '1',
                'description' => 'Withdrawal fee percentage (e.g. 1 = 1%)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'withdrawal_fee_min',
                'value' => '50',
                'description' => 'Minimum withdrawal fee in naira',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'withdrawal_fee_cap',
                'value' => '2000',
                'description' => 'Maximum withdrawal fee cap in naira',
                'created_at' => now(),
                'updated_at' => now(),
            ],

        ];

        foreach ($settings as $setting) {
            DB::table('settings')->updateOrInsert(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}