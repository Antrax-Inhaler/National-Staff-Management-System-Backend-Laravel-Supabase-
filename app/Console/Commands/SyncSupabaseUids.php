<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\Http;

class SyncSupabaseUids extends Command
{
    protected $signature = 'supabase:sync-uids';
    protected $description = 'Sync Supabase UIDs to local users';

    public function handle()
    {
        $supabaseUrl = env('SUPABASE_URL');
        $serviceRoleKey = env('SUPABASE_KEY');

        $this->line('Supabase URL: ' . $supabaseUrl);
        $this->line('Key length: ' . strlen($serviceRoleKey));

        // Get all users from Supabase with proper REST API endpoint
        try {
            $response = Http::withHeaders([
                'apikey' => $serviceRoleKey,
                'Authorization' => 'Bearer ' . $serviceRoleKey,
                'Content-Type' => 'application/json',
            ])->get("{$supabaseUrl}/rest/v1/users?select=*");

            if (!$response->successful()) {
                $this->error('Failed to fetch users from Supabase: ' . $response->status());
                $this->line('Response: ' . $response->body());
                
                // Try alternative endpoint
                $this->tryAlternativeEndpoint($supabaseUrl, $serviceRoleKey);
                return;
            }

            $supabaseUsers = $response->json();
            $this->processUsers($supabaseUsers);

        } catch (\Exception $e) {
            $this->error('Exception: ' . $e->getMessage());
            $this->tryAlternativeEndpoint($supabaseUrl, $serviceRoleKey);
        }
    }

    protected function tryAlternativeEndpoint($supabaseUrl, $serviceRoleKey)
    {
        $this->line("\nTrying alternative endpoint: /auth/v1/admin/users");
        
        try {
            $response = Http::withHeaders([
                'apikey' => $serviceRoleKey,
                'Authorization' => 'Bearer ' . $serviceRoleKey,
            ])->get("{$supabaseUrl}/auth/v1/admin/users");

            if ($response->successful()) {
                $supabaseUsers = $response->json();
                $this->processUsers($supabaseUsers);
            } else {
                $this->error('Alternative endpoint also failed: ' . $response->status());
                $this->line('Response: ' . $response->body());
                
                // Try one more endpoint
                $this->tryAuthUsersEndpoint($supabaseUrl, $serviceRoleKey);
            }
        } catch (\Exception $e) {
            $this->error('Alternative endpoint exception: ' . $e->getMessage());
        }
    }

    protected function tryAuthUsersEndpoint($supabaseUrl, $serviceRoleKey)
    {
        $this->line("\nTrying PostgREST endpoint: /rest/v1/auth_users");
        
        try {
            $response = Http::withHeaders([
                'apikey' => $serviceRoleKey,
                'Authorization' => 'Bearer ' . $serviceRoleKey,
            ])->get("{$supabaseUrl}/rest/v1/auth_users?select=*");

            if ($response->successful()) {
                $supabaseUsers = $response->json();
                $this->processUsers($supabaseUsers);
            } else {
                $this->error('Auth users endpoint failed: ' . $response->status());
                $this->manualSyncOption();
            }
        } catch (\Exception $e) {
            $this->error('Auth users endpoint exception: ' . $e->getMessage());
            $this->manualSyncOption();
        }
    }

    protected function manualSyncOption()
    {
        $this->line("\n💡 Manual sync option:");
        $this->line("1. Go to Supabase Dashboard → Authentication → Users");
        $this->line("2. Copy the UIDs from there");
        $this->line("3. Run: php artisan tinker");
        $this->line("4. Then: User::where('email', 'email@example.com')->update(['supabase_uid' => 'copied-uid'])");
    }

    protected function processUsers($supabaseUsers)
    {
        if (!is_array($supabaseUsers)) {
            $this->error('Invalid response format from Supabase');
            $this->line('Response: ' . json_encode($supabaseUsers));
            return;
        }

        $this->info('Found ' . count($supabaseUsers) . ' users in Supabase');
        
        // Display all Supabase users for debugging
        $this->line("\nSupabase Users:");
        foreach ($supabaseUsers as $index => $user) {
            $email = $user['email'] ?? ($user['user_metadata']['email'] ?? 'no-email');
            $uid = $user['id'] ?? 'no-id';
            $this->line("{$index}. {$email} -> {$uid}");
        }

        // Display all local users for debugging
        $this->line("\nLocal Users:");
        $localUsers = User::all();
        foreach ($localUsers as $user) {
            $this->line("{$user->id}. {$user->email} -> {$user->supabase_uid}");
        }

        $synced = 0;
        $this->line("\nAttempting to sync...");

        foreach ($supabaseUsers as $supabaseUser) {
            $email = $supabaseUser['email'] ?? ($supabaseUser['user_metadata']['email'] ?? null);
            $uid = $supabaseUser['id'] ?? null;

            if (!$email || !$uid || $email === 'no-email') {
                continue;
            }

            // Find local user by email (case-insensitive)
            $localUser = User::where('email', 'ilike', $email)->first();

            if ($localUser) {
                if ($localUser->supabase_uid !== $uid) {
                    $localUser->supabase_uid = $uid;
                    $localUser->save();
                    $synced++;
                    $this->line("✅ Synced: {$email} -> {$uid}");
                } else {
                    $this->line("ℹ️ Already synced: {$email}");
                }
            } else {
                $this->line("❌ No local user found for: {$email}");
            }
        }

        $this->info("\nSynced {$synced} users with Supabase UIDs");
        
        // Check if any local users are still missing UIDs
        $missingUsers = User::whereNull('supabase_uid')->get();
        if ($missingUsers->count() > 0) {
            $this->warn("\nUsers still missing Supabase UIDs:");
            foreach ($missingUsers as $user) {
                $this->line("❌ {$user->email} - No matching Supabase user found");
            }
        }

        // Verify the manual UIDs you added
        $this->line("\n🔍 Manual UID verification:");
        $usersWithUids = User::whereNotNull('supabase_uid')->get();
        foreach ($usersWithUids as $user) {
            $this->line("✓ {$user->email} -> {$user->supabase_uid}");
        }
    }
}