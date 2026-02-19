<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\v1\AffiliateOfficerSeeder;
use Database\Seeders\v1\OfficersSeeder;
use Database\Seeders\v1\RolesSeeder;
use Database\Seeders\v1\UserRolesSeeder;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // $this->call(RolesSeeder::class);
        // $this->call(OfficersSeeder::class);
        // $this->call(OrganizationAdminSeeder::class);
        $this->call(AffiliateOfficerSeeder::class);
    }
}
