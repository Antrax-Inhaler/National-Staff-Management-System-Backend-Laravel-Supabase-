<?php
// database/seeders/AffiliateOfficerSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Member;
use App\Models\AffiliateOfficer;
use App\Models\OfficerPosition;
use App\Models\Affiliate;

class AffiliateOfficerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function () {
            // Find the user by email
            $user = User::where('email', 'contact@organizemypeople.com')->first();
            
            if (!$user) {
                $this->command->error('User contact@organizemypeople.com not found. Please create the user first.');
                return;
            }

            // Find the member record for this user
            $member = Member::where('user_id', $user->id)->first();
            
            if (!$member) {
                $this->command->error('Member record not found for user contact@organizemypeople.com');
                return;
            }

            // Get the affiliate (assuming the member already has an affiliate)
            $affiliate = Affiliate::find($member->affiliate_id);
            
            if (!$affiliate) {
                $this->command->error('Affiliate not found for this member. Please ensure the member has an affiliate.');
                return;
            }

            // Get the President position (position_id = 1)
            $presidentPosition = OfficerPosition::find(1);
            
            if (!$presidentPosition) {
                $this->command->error('President position (ID: 1) not found. Please run the OfficerPositionsSeeder first.');
                return;
            }

            // Check if the member already has this officer position
            $existingOfficer = AffiliateOfficer::where('affiliate_id', $affiliate->id)
                ->where('position_id', $presidentPosition->id)
                ->where('member_id', $member->id)
                ->first();

            if ($existingOfficer) {
                $this->command->info('User contact@organizemypeople.com already has President role for this affiliate.');
                return;
            }

            // Check if the position is already occupied by someone else
            $positionOccupied = AffiliateOfficer::where('affiliate_id', $affiliate->id)
                ->where('position_id', $presidentPosition->id)
                ->where('is_vacant', 0)
                ->where(function($query) {
                    $query->whereNull('end_date')
                          ->orWhere('end_date', '>', now());
                })
                ->exists();

            if ($positionOccupied) {
                $this->command->warn('President position is already occupied in this affiliate. Creating new officer record anyway...');
            }

            // Assign the President role
            AffiliateOfficer::create([
                'affiliate_id' => $affiliate->id,
                'position_id' => $presidentPosition->id,
                'member_id' => $member->id,
                'start_date' => Carbon::now()->format('Y-m-d'),
                'end_date' => null, // No end date - ongoing position
                'is_vacant' => 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'created_by' => $user->id, // Self-assigned
            ]);

            $this->command->info('Successfully assigned President role to contact@organizemypeople.com');
            $this->command->info("Affiliate: {$affiliate->name} (ID: {$affiliate->id})");
            $this->command->info("Member ID: {$member->id}");
            $this->command->info("Position: {$presidentPosition->name} (ID: {$presidentPosition->id})");
        });
    }
}