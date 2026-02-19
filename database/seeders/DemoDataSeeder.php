<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Insert Affiliates
        DB::table('affiliates')->insert([
            ['name' => 'Affiliate One', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Affiliate Two', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Insert Users
        DB::table('users')->insert([
            [
                'name' => 'Andrei',
                'email' => 'andrei@organizemypeople.com',
                'password' => Hash::make('placeholder'), // hash so Laravel auth won't break
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Bob',
                'email' => 'jovenandrei0324@gmail.com',
                'password' => Hash::make('placeholder'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Insert Members
        DB::table('members')->insert([
            [
                'user_id' => 1,
                'affiliate_id' => 1,
                'member_id' => 'M001',
                'first_name' => 'Andrei',
                'last_name' => 'Lagahit',
                'level' => 'Professional',
                'employment_status' => 'Full Time',
                'status' => 'Active',
                'city' => 'Chicago',
                'state' => 'IL',
                'zip_code' => '60601',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => 2,
                'affiliate_id' => 2,
                'member_id' => 'M002',
                'first_name' => 'Bob',
                'last_name' => 'Smith',
                'level' => 'Associate',
                'employment_status' => 'Part Time',
                'status' => 'Active',
                'city' => 'New York',
                'state' => 'NY',
                'zip_code' => '10001',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
