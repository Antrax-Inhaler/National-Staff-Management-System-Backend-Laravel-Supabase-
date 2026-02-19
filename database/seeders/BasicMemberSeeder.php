<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class BasicMemberSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // First, create the affiliate if it doesn't exist
        $affiliateId = DB::table('affiliates')->insertGetId([
            'name' => 'Test Affiliate',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Create the user
        $userId = DB::table('users')->insertGetId([
            'name' => 'Joven Andrei',
            'email' => 'jovenandrei0324@gmail.com',
            'email_verified_at' => Carbon::now(),
            'password' => Hash::make(uniqid()), // Random password since we use magic links
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Create the member record
        DB::table('members')->insert([
            'user_id' => $userId,
            'affiliate_id' => $affiliateId,
            'member_id' => 'MEM' . str_pad($userId, 4, '0', STR_PAD_LEFT),
            'first_name' => 'Joven',
            'last_name' => 'Andrei',
            'level' => 'Professional',
            'employment_status' => 'Full Time',
            'status' => 'Active',
            'work_email' => 'jovenandrei0324@gmail.com',
            'non_nso' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->command->info('Basic member user created successfully!');
        $this->command->info('Email: jovenandrei0324@gmail.com');
        $this->command->info('No password needed - uses magic link authentication');
    }
}