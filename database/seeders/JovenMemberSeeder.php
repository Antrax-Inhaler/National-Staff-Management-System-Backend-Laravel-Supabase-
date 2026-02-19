<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class JovenMemberSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // ✅ Create user
        $userId = DB::table('users')->insertGetId([
            'name' => 'Joven Andrei',
            'email' => 'jovenandrei0324@gmail.com',
            'email_verified_at' => Carbon::now(),
            'password' => Hash::make(uniqid()), // random since magic links are used
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // ✅ Create member
        $memberId = DB::table('members')->insertGetId([
            'user_id' => $userId,
            'affiliate_id' => null, // kung wala pa siyang affiliate
            'member_id' => 'MEM' . str_pad($userId, 4, '0', STR_PAD_LEFT),
            'first_name' => 'Joven',
            'last_name' => 'Andrei',
            'level' => 'Regular',
            'employment_status' => 'Full Time',
            'status' => 'Active',
            'work_email' => 'jovenandrei0324@gmail.com',
            'non_nso' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->command->info('✅ Joven Andrei created successfully!');
        $this->command->info('Email: jovenandrei0324@gmail.com');
        $this->command->info('User ID: ' . $userId);
        $this->command->info('Member ID: ' . $memberId);
    }
}
