<?php

namespace Database\Seeders\v1;

use App\Models\UserRole;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserRolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        UserRole::updateOrCreate([
            "user_id" => 1,
            "role_id" => 1
        ]);
    }
}
