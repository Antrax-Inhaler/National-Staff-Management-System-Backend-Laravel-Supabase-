<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Services\SupabaseUserService;

class FixMissingSupabaseUid extends Command
{
    protected $signature = 'fix:supabase-uids 
                            {--limit=100 : Limit number of users to process}
                            {--batch=10 : Process users in batches}
                            {--dry-run : Show what would be done without actually doing it}
                            {--skip-test-emails : Skip test email addresses (example.com, test.com)}
                            {--skip-failures : Continue even if some users fail}
                            {--production : Force use production database}';
    
    protected $description = 'Create Supabase accounts for users missing supabase_uid in production database';

    private array $testEmailDomains = [
        'example.com',
        'test.com',
        'example.org',
        'test.org',
        'example.net',
        'test.net',
    ];
    
    private string $tableName = 'production.users'; // Direct production table
    private SupabaseUserService $supabaseService;

    public function __construct()
    {
        parent::__construct();
        $this->supabaseService = app(SupabaseUserService::class);
    }

    public function handle()
    {
        $limit = $this->option('limit');
        $batchSize = $this->option('batch');
        $dryRun = $this->option('dry-run');
        $skipTestEmails = $this->option('skip-test-emails');
        $skipFailures = $this->option('skip-failures');
        $useProduction = $this->option('production');
        
        $this->info("Starting Supabase UID fix process for PRODUCTION database...");
        $this->info("Using table: {$this->tableName}");
        
        // Get users with email but no supabase_uid from PRODUCTION
        $query = DB::table('production.users')
                    ->whereNotNull('email')
                    ->whereNull('supabase_uid')
                    ->where('email', '!=', '');
        
        if ($skipTestEmails) {
            foreach ($this->testEmailDomains as $domain) {
                $query->where('email', 'NOT LIKE', '%@' . $domain);
            }
        }
        
        $totalUsers = $query->count();
        $this->info("Total users needing fix in production: {$totalUsers}");
        
        $users = $query->orderBy('id')
                      ->take($limit)
                      ->get(['id', 'email', 'name', 'supabase_uid']);
        
        $this->info("Processing {$users->count()} users...");
        
        if ($users->isEmpty()) {
            $this->info("No users need fixing!");
            return 0;
        }
        
        $successCount = 0;
        $failCount = 0;
        $skippedCount = 0;
        $batchCount = 0;
        
        foreach ($users->chunk($batchSize) as $batch) {
            $batchCount++;
            $this->info("\n--- Processing Batch {$batchCount} ({$batch->count()} users) ---");
            
            foreach ($batch as $user) {
                $this->line("Processing user #{$user->id}: {$user->email}");
                
                // Skip test emails if option is set
                if ($skipTestEmails && $this->isTestEmail($user->email)) {
                    $this->warn("Skipping test email: {$user->email}");
                    $skippedCount++;
                    continue;
                }
                
                // Validate email format
                if (!$this->isValidEmail($user->email)) {
                    $this->error("Invalid email format: {$user->email}");
                    $failCount++;
                    continue;
                }
                
                if ($dryRun) {
                    $this->info("[DRY RUN] Would create Supabase account for: {$user->email}");
                    $skippedCount++;
                    continue;
                }
                
                DB::beginTransaction();
                
                try {
                    // Double-check user doesn't already have supabase_uid (re-fetch from DB)
                    $currentUser = DB::table('production.users')
                        ->where('id', $user->id)
                        ->first(['supabase_uid']);
                    
                    if (!empty($currentUser->supabase_uid)) {
                        $this->warn("Already has supabase_uid: {$currentUser->supabase_uid}");
                        $skippedCount++;
                        DB::rollBack();
                        continue;
                    }
                    
                    $this->info("Creating Supabase account for: {$user->email}");
                    
                    // Create Supabase account
                    $supabaseUid = $this->createSupabaseAccount($user->email, $user->name);
                    
                    if ($supabaseUid) {
                        // Update production.users directly
                        $updated = DB::table('production.users')
                            ->where('id', $user->id)
                            ->update([
                                'supabase_uid' => $supabaseUid,
                                'updated_at' => now(),
                            ]);
                        
                        if ($updated) {
                            $this->info("✅ Success! Supabase UID saved to production: {$supabaseUid}");
                            $successCount++;
                            DB::commit();
                            
                            // Verify the update
                            $verify = DB::table('production.users')
                                ->where('id', $user->id)
                                ->value('supabase_uid');
                            
                            if ($verify === $supabaseUid) {
                                $this->info("✅ Verified in production database");
                            } else {
                                $this->error("❌ Verification failed!");
                            }
                        } else {
                            $this->error("❌ Failed to update production database");
                            $failCount++;
                            DB::rollBack();
                        }
                    } else {
                        $this->error("❌ Failed to create Supabase account");
                        $failCount++;
                        DB::rollBack();
                    }
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    
                    $errorMessage = $e->getMessage();
                    Log::error("Failed to create Supabase account for user #{$user->id}", [
                        'email' => $user->email,
                        'error' => $errorMessage,
                        'trace' => $e->getTraceAsString()
                    ]);
                    
                    $formattedError = $this->formatError($errorMessage);
                    $this->error("❌ Error: {$formattedError}");
                    
                    if (strpos($errorMessage, 'duplicate') !== false || 
                        strpos($errorMessage, 'already exists') !== false ||
                        strpos($errorMessage, '409') !== false ||
                        strpos($errorMessage, 'email_exists') !== false) {
                        
                        $this->warn("⚠️  Email already exists in Supabase. Attempting to find existing UID...");
                        
                        // Try to find existing Supabase user
                        $existingUid = $this->findExistingSupabaseUser($user->email);
                        if ($existingUid) {
                            $this->info("Found existing Supabase UID: {$existingUid}");
                            
                            // Update with existing UID
                            $updated = DB::table('production.users')
                                ->where('id', $user->id)
                                ->update(['supabase_uid' => $existingUid, 'updated_at' => now()]);
                            
                            if ($updated) {
                                $this->info("✅ Linked existing Supabase account!");
                                $successCount++;
                            }
                        }
                    }
                    
                    if ($skipFailures) {
                        $this->warn("Skipping due to --skip-failures flag");
                        $skippedCount++;
                    } else {
                        $failCount++;
                    }
                }
                
                // Small delay to avoid rate limiting (2 seconds)
                if (!$dryRun) {
                    sleep(2);
                }
            }
            
            $this->info("Batch {$batchCount} complete. Success: {$successCount}, Failed: {$failCount}, Skipped: {$skippedCount}");
            
            // Add longer delay between batches
            if (!$dryRun && $batchCount < ceil($users->count() / $batchSize)) {
                $this->info("Waiting 5 seconds before next batch...");
                sleep(5);
            }
        }
        
        $this->newLine();
        $this->info("=== FINAL SUMMARY ===");
        $this->info("Total users in query: {$users->count()}");
        $this->info("Successfully created/updated: {$successCount}");
        $this->info("Failed: {$failCount}");
        $this->info("Skipped: {$skippedCount}");
        
        // Show current status
        $withUid = DB::table('production.users')->whereNotNull('supabase_uid')->count();
        $withoutUid = DB::table('production.users')->whereNull('supabase_uid')->whereNotNull('email')->count();
        
        $this->info("\n📊 Current production database status:");
        $this->info("Total users with supabase_uid: {$withUid}");
        $this->info("Total users still missing supabase_uid: {$withoutUid}");
        
        if ($withoutUid > 0) {
            $this->warn("\n⚠️  Some users still missing supabase_uid.");
            $this->line("Run this command again with higher limit: php artisan fix:supabase-uids --limit={$withoutUid}");
        }
        
        if ($failCount > 0) {
            $this->error("\n⚠️  Some users failed. Check logs for details.");
            
            if (!$skipFailures) {
                $this->error("Use --skip-failures flag to continue despite errors.");
            }
            
            return 1;
        }
        
        $this->info("\n✅ Process completed successfully!");
        
        return 0;
    }
    
    /**
     * Create Supabase account and return UID
     */
    private function createSupabaseAccount(string $email, string $name): ?string
    {
        try {
            $plainPassword = bin2hex(random_bytes(8)); // 16 chars password
            
            Log::info('Creating Supabase account', [
                'email' => $email,
                'name' => $name
            ]);
            
            // Call Supabase service
            $result = $this->supabaseService->createUser($email, $plainPassword);
            
            $supabaseId = $result['id'] ?? null;
            
            if ($supabaseId) {
                Log::info('Supabase account created successfully', [
                    'email' => $email,
                    'supabase_uid' => $supabaseId
                ]);
                return $supabaseId;
            } else {
                Log::error('Failed to get Supabase ID from response', [
                    'email' => $email,
                    'response' => $result
                ]);
                return null;
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to create Supabase account', [
                'email' => $email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    
    /**
     * Try to find existing Supabase user by email
     */
    private function findExistingSupabaseUser(string $email): ?string
    {
        try {
            // Since Supabase doesn't have direct email lookup in admin API,
            // we'll try to create and catch the duplicate error
            // The error handling above will catch duplicates
            
            // Alternative: You could query Supabase auth.users table directly if you have access
            // For now, return null and let the manual process handle it
            
            Log::warning("Need manual intervention for existing Supabase user", ['email' => $email]);
            return null;
            
        } catch (\Exception $e) {
            Log::error("Error finding existing Supabase user", [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    private function formatError(string $error): string
    {
        // Extract the main error message
        $lines = explode("\n", $error);
        return $lines[0] ?? substr($error, 0, 100);
    }
    
    private function isTestEmail(string $email): bool
    {
        foreach ($this->testEmailDomains as $domain) {
            if (strpos($email, '@' . $domain) !== false) {
                return true;
            }
        }
        return false;
    }
    
    private function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}