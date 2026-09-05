<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        Admin::updateOrCreate(
            [
                'email' => 'admin@cherishlyng.com',
            ],
            [
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'password' => Hash::make('Admin@2026!'),
                'role' => 'super_admin',
                'is_active' => true,
            ]
        );
    }
}