<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;

class CheckSupabaseUsers extends Command
{
    protected $signature = 'supabase:check-users';
    protected $description = 'Check if users have supabase_uid';

    public function handle()
    {
        $users = User::with('member')->get();
        
        $this->info("Total users: " . $users->count());
        
        foreach ($users as $user) {
            $hasUid = $user->supabase_uid ? 'YES' : 'NO';
            $hasMember = $user->member ? 'YES' : 'NO';
            $memberId = $user->member ? $user->member->id : 'N/A';
            
            $this->line("User: {$user->email} | UID: {$hasUid} | Member: {$hasMember} | Member ID: {$memberId}");
        }
    }
}