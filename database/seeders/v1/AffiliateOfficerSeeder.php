<?php

namespace Database\Seeders\v1;

use App\Enums\RoleEnum;
use App\Models\AffiliateOfficer;
use App\Models\Member;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AffiliateOfficerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $role = Role::where('name', RoleEnum::AFFILIATE_OFFICER->value)->first();
        
        $officers = AffiliateOfficer::with('member.user')
            ->whereNull('end_date')
            ->get();

        foreach ($officers as $officer) {
            $user = $officer->member?->user;

            if (! $user) {
                continue;
            }

            $user->roles()->syncWithoutDetaching($role->id);
        }
    }
}
