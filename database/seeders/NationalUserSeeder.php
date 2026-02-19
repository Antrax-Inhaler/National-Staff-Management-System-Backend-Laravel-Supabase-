<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use App\Models\User;

class OrganizationUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
  public function run(): void
    {
        // Drop existing user if found
        $existingUser = User::where('email', 'fermin@organizemypeople.com')->first();
        if ($existingUser) {
            $existingUser->delete(); // also deletes from Supabase (thanks to your model!)
            $this->command->warn('Deleted existing user: andrei@organizemypeople.com');
        }

        // Create the user via Eloquent
        $user = User::create([
            'name' => 'Andrei Organization User',
            'email' => 'fermin@organizemypeople.com',
            'password' => Hash::make(uniqid()), // dummy, we use Supabase Auth
            'email_verified_at' => now(),
        ]);

        $this->command->info('New national user created in DB and Supabase: ' . $user->email);

        // Create the member record
        $memberId = DB::table('members')->insertGetId([
            'user_id' => $user->id,
            'affiliate_id' => null,
            'member_id' => 'NAT' . str_pad($user->id, 4, '0', STR_PAD_LEFT),
            'first_name' => 'Andrei',
            'last_name' => 'Organization',
            'level' => 'Administrator',
            'employment_status' => 'Full Time',
            'status' => 'Active',
            'work_email' => $user->email,
            'non_nso' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Assign national role
        $adminRoleId = DB::table('national_roles')->where('name', 'Organization Administrator')->value('id');
        if ($adminRoleId) {
            DB::table('member_national_roles')->insert([
                'member_id' => $memberId,
                'role_id' => $adminRoleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->command->info('User assigned as Organization Administrator.');
        } else {
            $this->command->error('Organization Administrator role not found.');
        }
    }
}
