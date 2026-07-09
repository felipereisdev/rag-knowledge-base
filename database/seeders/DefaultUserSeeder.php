<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class DefaultUserSeeder extends Seeder
{
    public function run(): void
    {
        if (User::where('email', 'admin@rag.local')->exists()) {
            return;
        }

        try {
            User::create([
                'name' => 'Admin',
                'email' => 'admin@rag.local',
                'password' => 'password',
            ]);
        } catch (\Throwable $e) {
            Log::error('DefaultUserSeeder failed: '.$e->getMessage());
        }
    }
}
