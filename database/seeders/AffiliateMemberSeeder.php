<?php

namespace Database\Seeders;

use App\Enums\RoleEnum;
use App\Models\Member;
use App\Models\Role;
use App\Models\UserRole;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AffiliateMemberSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $members = Member::whereHas('user', function ($userQuery) {
            $userQuery->whereDoesntHave('roles');
        })
            ->whereNotNull('affiliate_id')
            ->get();

        $role = Role::where('name', RoleEnum::AFFILIATE_MEMBER)->first();

        foreach ($members as $member) {
            UserRole::updateOrCreate([
                'user_id' => $member->user_id,
                'role_id' => $role->id,
            ]);
        }
    }
}
