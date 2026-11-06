<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminSeeder extends Seeder
{
    /**
     * Creates the first super admin only if there isn't one, so deploys never
     * touch an existing password. Set ADMIN_SEED_PASSWORD to choose the first
     * password; otherwise a random one is printed once.
     */
    public function run(): void
    {
        $email = 'admin@cherishlyng.com';

        if (Admin::where('email', $email)->exists()) {
            return;
        }

        $password = env('ADMIN_SEED_PASSWORD') ?: Str::random(24);

        Admin::create([
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => $email,
            'password' => $password,
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        if (!env('ADMIN_SEED_PASSWORD')) {
            $this->command?->warn("Created {$email} with password: {$password}");
        }
    }
}
