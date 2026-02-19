<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class DebugUserSupabase extends Command
{
    protected $signature = 'debug:user-supabase {email}';
    protected $description = 'Debug Supabase UID issues for a specific user';

    public function handle()
    {
        $email = $this->argument('email');
        
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            $this->error("User not found: {$email}");
            return;
        }
        
        $this->info("User found:");
        $this->table(['Field', 'Value'], [
            ['ID', $user->id],
            ['Email', $user->email],
            ['Supabase UID', $user->supabase_uid ?? 'NULL'],
            ['Created At', $user->created_at],
            ['Updated At', $user->updated_at],
        ]);
        
        // Test creating Supabase account
        $this->info("\nTesting Supabase account creation...");
        
        try {
            $result = $user->createSupabaseAccount();
            $this->info("createSupabaseAccount() returned: " . ($result ? 'true' : 'false'));
            
            $user->refresh();
            $this->info("After refresh - Supabase UID: " . ($user->supabase_uid ?? 'NULL'));
            
            if ($user->supabase_uid) {
                $this->info("✅ Success! UID: {$user->supabase_uid}");
            } else {
                $this->error("❌ UID still null!");
            }
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }
    }
}