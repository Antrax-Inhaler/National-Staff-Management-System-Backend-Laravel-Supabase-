<?php
// database/seeders/OrganizationAdminSeeder.php

namespace Database\Seeders;

use App\Enums\RoleEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Member;
use App\Models\MemberOrganizationRole;
use App\Models\OrganizationRole;
use App\Models\Role;
use App\Models\UserRole;

class OrganizationAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function () {
            // Find the user by email

            $email = 'andrei@organizemypeople.com';
            $firstname = 'Andrei';
            $lastname = 'Lagahit';

            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'email' => $email,
                    'name' => $firstname . ' ' . $lastname,
                    'password' => Hash::make('password')
                ]
            );

            Member::firstOrCreate(
                ['user_id' => $user->id,],
                [
                    'first_name' => $firstname,
                    'last_name' => $lastname,
                    'work_email' => $email,
                    'home_email' => $email,
                ]
            );

            $national_admin = Role::firstWhere('name', RoleEnum::NATIONAL_ADMINISTRATOR->value);
            $affiliate_member = Role::firstWhere('name', RoleEnum::AFFILIATE_MEMBER->value);

            UserRole::create([
                'user_id' => $user->id,
                'role_id' => $national_admin->id
            ]);

            UserRole::create([
                'user_id' => $user->id,
                'role_id' => $affiliate_member->id
            ]);
        });
    }
}
