<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run()
    {
        // Andrei (member_id = 1) → Organization Administrator + President of Affiliate One
        DB::table('member_national_roles')->insert([
            'member_id' => 1,
            'role_id'   => 1, // Organization Administrator
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('affiliate_officers')->insert([
            'affiliate_id' => 1,
            'position_id'  => 1, // President
            'member_id'    => 1,
            'start_date'   => now()->toDateString(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Bob (member_id = 2) → Vice President of Affiliate Two
        DB::table('affiliate_officers')->insert([
            'affiliate_id' => 2,
            'position_id'  => 2, // Vice President
            'member_id'    => 2,
            'start_date'   => now()->toDateString(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }
}
