<?php

namespace App\Services\CsvImport;

use App\Models\Member;
use App\Models\Affiliate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class MemberService
{
    private DataCleanerService $dataCleaner;
    
    public function __construct(DataCleanerService $dataCleaner)
    {
        $this->dataCleaner = $dataCleaner;
    }

    /**
     * Create or update member - prioritize membership ID lookup
     */
    public function createOrUpdateMember(
        int $userId,
        array $record,
        ?int $affiliateId,
        int $createdBy
    ): Member {
        try {
            // DEBUG: First, let's see ALL columns in the record
            Log::debug("=== ALL COLUMNS IN RECORD ===");
            foreach ($record as $key => $value) {
                Log::debug("Column: '{$key}' = '{$value}'");
            }
            
            // Extract and clean data - handle missing fields with null checks
            $firstName = isset($record['First Name']) && !empty(trim($record['First Name'])) 
                ? $this->dataCleaner->cleanName($record['First Name'])
                : '.';

            $lastName = isset($record['Last Name']) && !empty(trim($record['Last Name']))
                ? $this->dataCleaner->cleanName($record['Last Name'])
                : '.';
            
            $homeEmail = isset($record['Home Email']) && !empty(trim($record['Home Email']))
                ? $this->dataCleaner->cleanEmail($record['Home Email'])
                : null;
            
            $workEmail = $homeEmail; // Use home email for both
            
            // If we have no name at all, use a placeholder
            if (!$firstName && !$lastName) {
                $firstName = 'Unknown';
                $lastName = 'User';
                Log::warning("No name provided for user {$userId} - using placeholder");
            }

            // Clean additional fields with null checks
            $membershipId = isset($record['Membership ID']) && !empty(trim($record['Membership ID']))
                ? $this->dataCleaner->cleanMembershipId($record['Membership ID'])
                : null;
            
            $level = isset($record['Level']) && !empty(trim($record['Level']))
                ? $this->dataCleaner->cleanMemberLevel($record['Level'])
                : null;
            
            $status = isset($record['Status']) && !empty(trim($record['Status']))
                ? $this->dataCleaner->cleanMemberStatus($record['Status'])
                : null;
            
            // DEBUG: Check employment status column directly
            Log::debug("=== CHECKING EMPLOYMENT STATUS COLUMNS ===");
            $employmentStatus = null;
            
            // Try ALL possible column names
            $empStatusKeys = ['Emp. status', 'Emp. Status', 'Emp status', 'Emp Status', 
                             'Employment Status', 'employment status', 'EmpStatus', 
                             'Emp.  status', 'Emp.  Status'];
            
            foreach ($empStatusKeys as $key) {
                if (isset($record[$key])) {
                    $rawValue = $record[$key];
                    Log::debug("Found employment status column '{$key}': '{$rawValue}'", [
                        'exists' => true,
                        'raw_value' => $rawValue,
                        'trimmed' => trim($rawValue),
                        'not_empty' => !empty(trim($rawValue))
                    ]);
                    
                    if (!empty(trim($rawValue))) {
                        $employmentStatus = $this->dataCleaner->cleanEmploymentStatus($rawValue);
                        Log::debug("Employment status cleaned", [
                            'key_used' => $key,
                            'raw' => $rawValue,
                            'cleaned' => $employmentStatus
                        ]);
                        break;
                    }
                } else {
                    Log::debug("Employment status column '{$key}': NOT FOUND");
                }
            }
            
            // If still not found, try case-insensitive search
            if (is_null($employmentStatus)) {
                Log::debug("=== TRYING CASE-INSENSITIVE SEARCH FOR EMP STATUS ===");
                foreach ($record as $recordKey => $recordValue) {
                    $lowerRecordKey = strtolower($recordKey);
                    if (strpos($lowerRecordKey, 'emp') !== false || 
                        strpos($lowerRecordKey, 'employment') !== false ||
                        strpos($lowerRecordKey, 'status') !== false) {
                        Log::debug("Potential employment status column: '{$recordKey}' = '{$recordValue}'");
                        
                        if (!empty(trim($recordValue))) {
                            $employmentStatus = $this->dataCleaner->cleanEmploymentStatus($recordValue);
                            if ($employmentStatus) {
                                Log::debug("Found via case-insensitive search", [
                                    'key' => $recordKey,
                                    'value' => $recordValue,
                                    'cleaned' => $employmentStatus
                                ]);
                                break;
                            }
                        }
                    }
                }
            }
            
            $originalStatus = $record['Status'] ?? '';
            
            // If Status column contains "retired" (case-insensitive), set employment status to "Retired"
            if (stripos($originalStatus, 'retired') !== false && $employmentStatus !== 'Retired') {
                $employmentStatus = 'Retired';
                Log::debug("Overriding employment status to 'Retired' based on Status column");
            }
            
            $selfId = 'I choose not to identify'; // Default value
            
            // Try different variations for Self Id
            if (isset($record['Self id']) && !empty(trim($record['Self id']))) {
                $selfId = $this->dataCleaner->cleanSelfId($record['Self id']);
                Log::debug("Self Id extracted using 'Self id'", [
                    'raw' => $record['Self id'],
                    'cleaned' => $selfId
                ]);
            } elseif (isset($record['Self Id']) && !empty(trim($record['Self Id']))) {
                $selfId = $this->dataCleaner->cleanSelfId($record['Self Id']);
                Log::debug("Self Id extracted using 'Self Id'", [
                    'raw' => $record['Self Id'],
                    'cleaned' => $selfId
                ]);
            }
            
            // FIXED: Phone extraction with correct column names
            $homePhone = null;
            $workPhone = null;
            
            // Check for home phone with multiple possible column names
            if (isset($record['Home phone']) && !empty(trim($record['Home phone']))) {
                $homePhone = $this->dataCleaner->formatPhoneNumber($record['Home phone']);
                Log::debug("Home phone extracted using 'Home phone'", [
                    'raw' => $record['Home phone'],
                    'formatted' => $homePhone
                ]);
            } elseif (isset($record['Home Phone']) && !empty(trim($record['Home Phone']))) {
                $homePhone = $this->dataCleaner->formatPhoneNumber($record['Home Phone']);
                Log::debug("Home phone extracted using 'Home Phone'", [
                    'raw' => $record['Home Phone'],
                    'formatted' => $homePhone
                ]);
            }
            
            // Check for work/office phone with multiple possible column names
            if (isset($record['Office phone']) && !empty(trim($record['Office phone']))) {
                $workPhone = $this->dataCleaner->formatPhoneNumber($record['Office phone']);
                Log::debug("Office phone extracted using 'Office phone'", [
                    'raw' => $record['Office phone'],
                    'formatted' => $workPhone
                ]);
            } elseif (isset($record['Office Phone']) && !empty(trim($record['Office Phone']))) {
                $workPhone = $this->dataCleaner->formatPhoneNumber($record['Office Phone']);
                Log::debug("Office phone extracted using 'Office Phone'", [
                    'raw' => $record['Office Phone'],
                    'formatted' => $workPhone
                ]);
            } elseif (isset($record['Work phone']) && !empty(trim($record['Work phone']))) {
                $workPhone = $this->dataCleaner->formatPhoneNumber($record['Work phone']);
                Log::debug("Work phone extracted using 'Work phone'", [
                    'raw' => $record['Work phone'],
                    'formatted' => $workPhone
                ]);
            } elseif (isset($record['Work Phone']) && !empty(trim($record['Work Phone']))) {
                $workPhone = $this->dataCleaner->formatPhoneNumber($record['Work Phone']);
                Log::debug("Work phone extracted using 'Work Phone'", [
                    'raw' => $record['Work Phone'],
                    'formatted' => $workPhone
                ]);
            }
            
            $state = isset($record['State']) && !empty(trim($record['State']))
                ? $this->dataCleaner->formatState($record['State'])
                : null;
            
            // Prepare member data - allow null values for most fields
            $memberData = [
                'affiliate_id' => $affiliateId, // Can be null
                'member_id' => $membershipId, // Can be null
                'first_name' => $firstName,
                'last_name' => $lastName,
                'level' => $level, // Can be null
                'employment_status' => $employmentStatus, // Can be null
                'status' => $status, // Can be null
                'address_line1' => isset($record['Street']) && !empty(trim($record['Street']))
                    ? $this->dataCleaner->cleanAddress($record['Street'])
                    : null,
                'city' => isset($record['City']) && !empty(trim($record['City']))
                    ? $this->dataCleaner->cleanCity($record['City'])
                    : null,
                'state' => $state, // Can be null
                'zip_code' => isset($record['Zip']) && !empty(trim($record['Zip']))
                    ? $this->dataCleaner->cleanZipCode($record['Zip'])
                    : null,
                'work_email' => $workEmail, // Can be null
                'home_email' => $homeEmail, // Can be null
                'work_phone' => $workPhone, // Can be null
                'home_phone' => $homePhone, // Can be null
                'self_id' => $selfId, // Default to "I choose not to identify"
                'user_id' => $userId, // ALWAYS has value!
                'created_by' => $createdBy,
                'updated_by' => $createdBy,
                'updated_at' => now(),
            ];

            Log::debug("=== FINAL MEMBER DATA TO SAVE ===", [
                'user_id' => $userId,
                'membership_id' => $membershipId,
                'email' => $workEmail,
                'affiliate_id' => $affiliateId,
                'home_phone' => $homePhone,
                'work_phone' => $workPhone,
                'employment_status' => $employmentStatus,
                'self_id' => $selfId,
                'level' => $level,
                'status' => $status,
                'has_home_phone' => !is_null($homePhone),
                'has_work_phone' => !is_null($workPhone),
                'has_employment_status' => !is_null($employmentStatus),
                'has_first_name' => !empty($firstName),
                'has_last_name' => !empty($lastName),
                'has_email' => !empty($workEmail)
            ]);

            // FIXED: Proper find and update logic
            $member = null;
            $foundBy = null;

            // FIRST PRIORITY: Find by membership ID
            if (!empty($membershipId)) {
                $existingMember = Member::where('member_id', $membershipId)->first();
                
                if ($existingMember) {
                    $member = $existingMember;
                    $foundBy = 'membership_id';
                    Log::info("Found existing member by membership ID", [
                        'member_id' => $member->id,
                        'membership_id' => $membershipId,
                        'user_id' => $member->user_id,
                        'found_by' => $foundBy
                    ]);
                }
            }

            // SECOND PRIORITY: If no membership ID match, try work email
            if (!$member && !empty($workEmail)) {
                $existingMember = Member::where('work_email', $workEmail)->first();
                
                if ($existingMember) {
                    $member = $existingMember;
                    $foundBy = 'work_email';
                    Log::info("Found existing member by work email", [
                        'member_id' => $member->id,
                        'email' => $workEmail,
                        'user_id' => $member->user_id,
                        'found_by' => $foundBy
                    ]);
                }
            }

            // THIRD PRIORITY: Try home email
            if (!$member && !empty($homeEmail)) {
                $existingMember = Member::where('home_email', $homeEmail)->first();
                
                if ($existingMember) {
                    $member = $existingMember;
                    $foundBy = 'home_email';
                    Log::info("Found existing member by home email", [
                        'member_id' => $member->id,
                        'email' => $homeEmail,
                        'user_id' => $member->user_id,
                        'found_by' => $foundBy
                    ]);
                }
            }

            // FOURTH PRIORITY: Try by user_id (if we're updating an existing user)
            if (!$member && $userId) {
                $existingMember = Member::where('user_id', $userId)->first();
                
                if ($existingMember) {
                    $member = $existingMember;
                    $foundBy = 'user_id';
                    Log::info("Found existing member by user_id", [
                        'member_id' => $member->id,
                        'user_id' => $userId,
                        'found_by' => $foundBy
                    ]);
                }
            }

            // Update or create member
            if ($member) {
                // UPDATE EXISTING MEMBER
                Log::info("Updating existing member", [
                    'member_id' => $member->id,
                    'found_by' => $foundBy,
                    'old_membership_id' => $member->member_id,
                    'new_membership_id' => $membershipId,
                    'old_work_email' => $member->work_email,
                    'new_work_email' => $workEmail,
                    'old_home_email' => $member->home_email,
                    'new_home_email' => $homeEmail,
                    'old_user_id' => $member->user_id,
                    'new_user_id' => $userId,
                    'has_changes' => $member->getDirty()
                ]);
                
                // Check if user_id is different and needs update
                if ($member->user_id !== $userId) {
                    Log::warning("Member user_id is changing", [
                        'member_id' => $member->id,
                        'old_user_id' => $member->user_id,
                        'new_user_id' => $userId
                    ]);
                }
                
                // Update member data
                $member->update($memberData);
                
                Log::info("Member updated successfully", [
                    'member_id' => $member->id,
                    'changes' => $member->getChanges(),
                    'current_membership_id' => $member->member_id,
                    'current_work_email' => $member->work_email
                ]);
                
            } else {
                // CREATE NEW MEMBER
                Log::info("Creating new member", [
                    'membership_id' => $membershipId,
                    'work_email' => $workEmail,
                    'home_email' => $homeEmail,
                    'user_id' => $userId,
                    'affiliate_id' => $affiliateId,
                    'reason' => 'No existing member found'
                ]);
                
                // Ensure we have at least a placeholder name
                if (empty($memberData['first_name']) && empty($memberData['last_name'])) {
                    $memberData['first_name'] = 'Unknown';
                    $memberData['last_name'] = 'User';
                    Log::warning("Using placeholder name for new member");
                }
                
                // Check for duplicates before creating
                if (!empty($membershipId)) {
                    $duplicate = Member::where('member_id', $membershipId)->exists();
                    if ($duplicate) {
                        throw new \Exception("Duplicate membership ID found: {$membershipId}");
                    }
                }
                
                if (!empty($workEmail)) {
                    $duplicate = Member::where('work_email', $workEmail)->exists();
                    if ($duplicate) {
                        throw new \Exception("Duplicate work email found: {$workEmail}");
                    }
                }
                
                $member = Member::create($memberData);
                $member->wasRecentlyCreated = true;
                
                Log::info("New member created successfully", [
                    'member_id' => $member->id,
                    'membership_id' => $member->member_id,
                    'work_email' => $member->work_email,
                    'user_id' => $member->user_id
                ]);
            }

            return $member;
            
        } catch (\Exception $e) {
            Log::error("Failed to create/update member: " . $e->getMessage(), [
                'user_id' => $userId,
                'affiliate_id' => $affiliateId,
                'membership_id' => $membershipId ?? null,
                'record_sample' => $this->sanitizeRecordForLogging($record),
                'error_trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Find user by membership ID
     */
    public function findUserByMembershipId(string $membershipId): ?User
    {
        if (empty($membershipId)) {
            return null;
        }
        
        $member = DB::table('members')
            ->where('member_id', $membershipId)
            ->first();
        
        if ($member && $member->user_id) {
            return User::find($member->user_id);
        }
        
        return null;
    }

    /**
     * Helper method to sanitize record for logging
     */
    private function sanitizeRecordForLogging(array $record): array
    {
        $sanitized = [];
        foreach ($record as $key => $value) {
            $cleanKey = $this->dataCleaner->cleanString($key);
            $sanitized[$cleanKey] = substr($value, 0, 50) . (strlen($value) > 50 ? '...' : '');
        }
        return $sanitized;
    }
}