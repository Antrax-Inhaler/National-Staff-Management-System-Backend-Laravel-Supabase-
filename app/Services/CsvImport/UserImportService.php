<?php

namespace App\Services\CsvImport;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\SupabaseUserService;

class UserImportService
{
    private DataCleanerService $dataCleaner;
    private SupabaseUserService $supabaseService;
    
    public function __construct(DataCleanerService $dataCleaner)
    {
        $this->dataCleaner = $dataCleaner;
        $this->supabaseService = app(SupabaseUserService::class);
    }

    /**
     * Create or update user - prioritize membership ID lookup
     */
    public function createOrUpdateUser(
        ?string $email,
        string $firstName,
        string $lastName,
        ?string $affiliateName,
        int $userId,
        array $record = []
    ): User {
        DB::beginTransaction();
        
        try {
            // Clean names
            $firstName = empty(trim($firstName)) ? '.' : $this->dataCleaner->cleanName($firstName);
            $lastName = empty(trim($lastName)) ? '.' : $this->dataCleaner->cleanName($lastName);
            
            $name = trim($firstName . ' ' . $lastName);
            $membershipId = $this->dataCleaner->cleanMembershipId($record['Membership ID'] ?? '');
            
            Log::info("User import started", [
                'name' => $name,
                'email' => $email,
                'membership_id' => $membershipId
            ]);
            
            $user = null;
            $foundBy = null;
            
            // STEP 1: Try to find existing user by email (if email exists)
            if ($email && $this->dataCleaner->isValidEmail($email)) {
                if (!$this->dataCleaner->isOrgEmailRestricted($email, $affiliateName)) {
                    $user = User::where('email', $email)->first();
                    
                    if ($user) {
                        $foundBy = 'email';
                        Log::info("Found existing user by email", [
                            'user_id' => $user->id,
                            'email' => $email,
                            'found_by' => $foundBy,
                            'has_supabase_uid' => !empty($user->supabase_uid)
                        ]);
                    }
                } else {
                    Log::info("Email restricted for .org domain", [
                        'email' => $email,
                        'affiliate_name' => $affiliateName
                    ]);
                }
            }
            
            // STEP 2: Try to find by membership ID through member record
            if (!$user && !empty($membershipId)) {
                $member = DB::table('members')
                    ->where('member_id', $membershipId)
                    ->whereNotNull('user_id')
                    ->first();
                
                if ($member && $member->user_id) {
                    $user = User::find($member->user_id);
                    
                    if ($user) {
                        $foundBy = 'membership_id';
                        Log::info("Found user via membership ID", [
                            'user_id' => $user->id,
                            'membership_id' => $membershipId,
                            'found_by' => $foundBy,
                            'has_supabase_uid' => !empty($user->supabase_uid)
                        ]);
                    }
                }
            }
            
            // STEP 4: CREATE NEW USER ONLY IF NOT FOUND
            if (!$user) {
                Log::info("Creating new user", [
                    'name' => $name,
                    'has_email' => !empty($email),
                    'email' => $email,
                    'reason' => 'No existing user found'
                ]);
                
                $userData = [
                    'name' => $name,
                    'password' => Hash::make(Str::random(16)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                
                // Only add email if it's valid and not restricted
                if ($email && $this->dataCleaner->isValidEmail($email) && 
                    !$this->dataCleaner->isOrgEmailRestricted($email, $affiliateName)) {
                    $userData['email'] = $email;
                    $userData['email_verified_at'] = now();
                }
                
                // Create user WITHOUT triggering events
                $user = User::withoutEvents(function () use ($userData) {
                    return User::create($userData);
                });
                
                if (!$user) {
                    throw new \Exception("Failed to create user in database");
                }
                
                $user->wasRecentlyCreated = true;
                
                Log::info("New user created successfully", [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'supabase_uid' => $user->supabase_uid
                ]);
                
                // Create Supabase account for NEW user
                $this->createOrUpdateSupabaseAccount($user, $email);
                
            } else {
                // USER FOUND: Update existing user
                Log::info("Processing existing user", [
                    'user_id' => $user->id,
                    'found_by' => $foundBy,
                    'current_email' => $user->email,
                    'new_email' => $email,
                    'current_name' => $user->name,
                    'new_name' => $name,
                    'has_supabase_uid' => !empty($user->supabase_uid)
                ]);
                
                $needsUpdate = false;
                $updateData = [];
                
                // Update name if different
                if ($user->name !== $name) {
                    $updateData['name'] = $name;
                    $needsUpdate = true;
                }
                
                // Update email if provided and valid
                if ($email && $this->dataCleaner->isValidEmail($email) && 
                    !$this->dataCleaner->isOrgEmailRestricted($email, $affiliateName)) {
                    
                    if ($user->email !== $email) {
                        $updateData['email'] = $email;
                        $updateData['email_verified_at'] = now();
                        $needsUpdate = true;
                        
                        Log::info("Email needs update", [
                            'user_id' => $user->id,
                            'old_email' => $user->email,
                            'new_email' => $email
                        ]);
                    }
                }
                
                // Apply updates if needed
                if ($needsUpdate) {
                    $user->updateQuietly($updateData);
                }
                
                // CRITICAL: Always check and create Supabase account for existing users
                // This ensures ALL users with email get supabase_uid
                $this->createOrUpdateSupabaseAccount($user, $email ?: $user->email);
            }
            
            DB::commit();
            
            return $user;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error("Failed to create/update user: " . $e->getMessage(), [
                'email' => $email,
                'name' => $firstName . ' ' . $lastName,
                'membership_id' => $membershipId ?? null,
                'error_trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Create or update Supabase account for user
     * This handles both new and existing users
     */
    private function createOrUpdateSupabaseAccount(User $user, ?string $email): void
    {
        try {
            // Skip if no email
            if (empty($email)) {
                Log::info("Skipping Supabase creation - no email", [
                    'user_id' => $user->id,
                    'name' => $user->name
                ]);
                return;
            }
            
            // Skip if already has supabase_uid
            if (!empty($user->supabase_uid)) {
                Log::info("User already has supabase_uid", [
                    'user_id' => $user->id,
                    'supabase_uid' => $user->supabase_uid,
                    'email' => $email
                ]);
                return;
            }
            
            Log::info("Creating/updating Supabase account", [
                'user_id' => $user->id,
                'email' => $email,
                'name' => $user->name
            ]);
            
            // Try to find existing user in Supabase auth first
            $existingAuthUser = $this->findExistingAuthUser($email);
            
            if ($existingAuthUser) {
                // Link existing Supabase auth user
                $user->updateQuietly(['supabase_uid' => $existingAuthUser['id']]);
                Log::info("Linked existing Supabase auth user", [
                    'user_id' => $user->id,
                    'supabase_uid' => $existingAuthUser['id'],
                    'email' => $email
                ]);
            } else {
                // Create new Supabase account
                $this->createNewSupabaseAccount($user, $email);
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to create/update Supabase account", [
                'user_id' => $user->id,
                'email' => $email,
                'error' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString()
            ]);
            // Don't throw - continue with import even if Supabase fails
        }
    }
    
    /**
     * Find existing user in Supabase auth
     */
    private function findExistingAuthUser(string $email): ?array
    {
        try {
            Log::debug("Checking for existing Supabase auth user", ['email' => $email]);
            
            // Try to fetch user by email from Supabase auth
            // Note: Supabase admin API doesn't have direct email lookup
            // We'll try to create and if it fails with "email_exists", we know it exists
            
            // For now, we'll rely on the createUser method's existing logic
            // which already handles the "email_exists" case
            
            return null;
            
        } catch (\Exception $e) {
            Log::error("Failed to check existing Supabase auth user", [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Create new Supabase account
     */
    private function createNewSupabaseAccount(User $user, string $email): void
    {
        try {
            $plainPassword = bin2hex(random_bytes(8)); // 16 chars password
            
            Log::info("Creating new Supabase account", [
                'user_id' => $user->id,
                'email' => $email,
                'name' => $user->name
            ]);
            
            // Call Supabase service
            $result = $this->supabaseService->createUser($email, $plainPassword);
            
            $supabaseId = $result['id'] ?? null;
            
            if ($supabaseId) {
                $user->updateQuietly(['supabase_uid' => $supabaseId]);
                
                Log::info("Supabase account created successfully", [
                    'user_id' => $user->id,
                    'supabase_uid' => $supabaseId,
                    'email' => $email
                ]);
            } else {
                Log::error("Failed to get Supabase ID from response", [
                    'user_id' => $user->id,
                    'email' => $email,
                    'response' => $result
                ]);
            }
            
        } catch (\Exception $e) {
            // Check if error is "email already exists"
            if (strpos($e->getMessage(), 'email_exists') !== false ||
                strpos($e->getMessage(), 'already exists') !== false) {
                
                Log::warning("Supabase user already exists, trying to fetch", [
                    'user_id' => $user->id,
                    'email' => $email
                ]);
                
                // Try to fetch the existing user
                $this->fetchAndLinkExistingAuthUser($user, $email);
            } else {
                throw $e;
            }
        }
    }
    
    /**
     * Fetch and link existing Supabase auth user
     */
    private function fetchAndLinkExistingAuthUser(User $user, string $email): void
    {
        try {
            Log::info("Attempting to fetch existing Supabase user", [
                'user_id' => $user->id,
                'email' => $email
            ]);
            
            // Note: Supabase doesn't provide direct email lookup in admin API
            // We'll need to handle this differently
            
            // For now, we'll skip and log the issue
            Log::warning("Cannot fetch existing Supabase user by email - manual intervention needed", [
                'user_id' => $user->id,
                'email' => $email
            ]);
            
        } catch (\Exception $e) {
            Log::error("Failed to fetch existing Supabase auth user", [
                'user_id' => $user->id,
                'email' => $email,
                'error' => $e->getMessage()
            ]);
        }
    }
}