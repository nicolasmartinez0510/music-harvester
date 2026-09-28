<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('ADMIN_EMAIL', 'admin@music-harvester.local');
        $first = (string) env('ADMIN_FIRST_NAME', 'Admin');
        $last = (string) env('ADMIN_LAST_NAME', 'Harvester');

        User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => trim($first.' '.$last),
                'first_name' => $first,
                'last_name' => $last,
                'username' => (string) env('ADMIN_USERNAME', 'admin'),
                'password' => (string) env('ADMIN_PASSWORD', 'admin'),
                'email_verified_at' => now(),
                'avatar_id' => 'meme-01',
                'role' => 'admin',
                'server_storage_status' => 'approved',
                'download_destination' => 'server',
            ],
        );
    }
}
