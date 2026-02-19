<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class AffiliateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // First, create the affiliate if it doesn't exist
        $affiliateId = DB::table('affiliates')->insertGetId([
            'name' => 'Joven Andrei Affiliate',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Create the user
        $userId = DB::table('users')->insertGetId([
            'name' => 'Joven Andrei User',
            'email' => 'ironheartgasco@gmail.com',
            'supabase_uid' => 'cc0a9cf3-d019-49ec-ad05-8edd32f0fcab',
            'email_verified_at' => Carbon::now(),
            'password' => Hash::make(uniqid()), // Random password since we use magic links
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Create the member record
        $memberId = DB::table('members')->insertGetId([
            'user_id' => $userId,
            'affiliate_id' => $affiliateId,
            'member_id' => 'MEM' . str_pad($userId, 4, '0', STR_PAD_LEFT),
            'first_name' => 'Joven',
            'last_name' => 'User',
            'level' => 'Professional',
            'employment_status' => 'Full Time',
            'status' => 'Active',
            'work_email' => 'ironheartgasco@gmail.com',
            'non_nso' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Get officer position IDs
        $presidentId = DB::table('officer_positions')->where('name', 'President')->value('id');
        $vicePresidentId = DB::table('officer_positions')->where('name', 'Vice President')->value('id');
        $secretaryId = DB::table('officer_positions')->where('name', 'Secretary')->value('id');
        $treasurerId = DB::table('officer_positions')->where('name', 'Treasurer')->value('id');
        $grievanceId = DB::table('officer_positions')->where('name', 'Grievance Chair')->value('id');
        $bargainingId = DB::table('officer_positions')->where('name', 'Bargaining Chair')->value('id');

        // Make Fermin the President of the affiliate
        DB::table('affiliate_officers')->insert([
            'affiliate_id' => $affiliateId,
            'position_id' => $presidentId,
            'member_id' => $memberId,
            'start_date' => Carbon::now()->subMonths(6),
            'end_date' => null, // No end date - currently active
            'is_vacant' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Also make Fermin the Secretary (can hold multiple positions)
        DB::table('affiliate_officers')->insert([
            'affiliate_id' => $affiliateId,
            'position_id' => $secretaryId,
            'member_id' => $memberId,
            'start_date' => Carbon::now()->subMonths(3),
            'end_date' => null,
            'is_vacant' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->command->info('Joven affiliate user created successfully!');
        $this->command->info('Email: ironheartgasco@gmail.com');
        $this->command->info('Affiliate: Joven Affiliate');
        $this->command->info('Roles: President and Secretary');
        $this->command->info('No password needed - uses magic link authentication');
    }
}