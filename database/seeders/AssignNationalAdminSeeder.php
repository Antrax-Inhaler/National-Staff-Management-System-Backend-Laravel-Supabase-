<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AssignOrganizationAdminSeeder extends Seeder
{
    public function run(): void
    {
        $userId = 20;

        // Get or create member record for this user
        $member = DB::table('members')->where('user_id', $userId)->first();

        if (!$member) {
            $memberId = DB::table('members')->insertGetId([
                'user_id' => $userId,
                'affiliate_id' => null, // Organization role = not bound to affiliate
                'member_id' => 'NAT' . str_pad($userId, 4, '0', STR_PAD_LEFT),
                'first_name' => 'Organization',
                'last_name' => 'Admin',
                'level' => 'Administrator',
                'employment_status' => 'Full Time',
                'status' => 'Active',
                'work_email' => DB::table('users')->where('id', $userId)->value('email'),
                'non_nso' => 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            $this->command->info("Created member record for user #$userId.");
        } else {
            $memberId = $member->id;
            $this->command->warn("Member record already exists for user #$userId.");
        }

        // Get national administrator role ID
        $adminRoleId = DB::table('national_roles')->where('name', 'Organization Administrator')->value('id');

        if (!$adminRoleId) {
            $this->command->error("Organization Administrator role not found in national_roles table.");
            return;
        }

        // Assign role if not already
        $existing = DB::table('member_national_roles')
            ->where('member_id', $memberId)
            ->where('role_id', $adminRoleId)
            ->first();

        if ($existing) {
            $this->command->warn("User #$userId already assigned as Organization Administrator.");
        } else {
            DB::table('member_national_roles')->insert([
                'member_id' => $memberId,
                'role_id' => $adminRoleId,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
            $this->command->info("User #$userId assigned as Organization Administrator.");
        }
    }
}
