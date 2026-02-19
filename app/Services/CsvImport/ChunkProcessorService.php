<?php

namespace App\Services\CsvImport;

use App\Models\Member;
use App\Models\User;
use App\Models\Affiliate;
use App\Models\Role;
use App\Models\UserRole;
use App\Models\OrganizationRole;
use App\Models\MemberOrganizationRole;
use App\Models\OfficerPosition;
use App\Models\AffiliateOfficer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\CsvImportChunk;
use Illuminate\Support\Facades\Cache;


class ChunkProcessorService
{
    private DataCleanerService $dataCleaner;
    private CsvParserService $csvParser;
    private MemberService $memberService;
    private UserImportService $userImportService;
    private RoleAssignmentService $roleAssignmentService;
    
    private array $options = [];
    private float $startTime;

    public function __construct(
        DataCleanerService $dataCleaner,
        CsvParserService $csvParser,
        MemberService $memberService,
        UserImportService $userImportService,
        RoleAssignmentService $roleAssignmentService
    ) {
        $this->dataCleaner = $dataCleaner;
        $this->csvParser = $csvParser;
        $this->memberService = $memberService;
        $this->userImportService = $userImportService;
        $this->roleAssignmentService = $roleAssignmentService;
        $this->startTime = microtime(true);
    }

    /**
     * Process a chunk of CSV rows WITH PER-ROW LOGGING
     */
    public function processChunk(string $chunkId, array $chunkData): array
    {
        $chunk = CsvImportChunk::findOrFail($chunkId);
        $import = $chunk->import;
        
        Log::info("=== START PROCESSING CHUNK ===", [
            'chunk_id' => $chunkId,
            'chunk_index' => $chunk->chunk_index,
            'total_rows_to_process' => count($chunkData),
            'import_id' => $import->id,
            'import_processed_rows_before' => $import->processed_rows
        ]);
        
        $rowResults = [];
        $successCount = 0;
        $errorCount = 0;
        $createdCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;
        
        $rowCounter = 0;
        
        foreach ($chunkData as $rowIndex => $row) {
            $rowCounter++;
            $rowNumber = $chunk->start_row + $rowIndex + 1;
            
            Log::info("Processing row {$rowCounter}/" . count($chunkData) . " (absolute row {$rowNumber})", [
                'chunk_id' => $chunkId,
                'row_index' => $rowIndex,
                'row_counter' => $rowCounter,
                'total_in_chunk' => count($chunkData)
            ]);
            
            $result = [
                'row_number' => $rowNumber,
                'data' => $this->sanitizeRowForLogging($row),
                'timestamp' => now()->toDateTimeString(),
            ];

            try {
                // Process the row
                $processedResult = $this->processRow($row, $rowNumber);
                
                if ($processedResult['status'] === 'created') {
                    $createdCount++;
                    $result['action'] = 'created';
                    $result['message'] = $processedResult['message'] ?? 'Record created successfully';
                    $result['record_id'] = $processedResult['member_id'] ?? $processedResult['user_id'] ?? null;
                    $result['member_id'] = $processedResult['member_id'] ?? null;
                    $result['user_id'] = $processedResult['user_id'] ?? null;
                    $result['affiliate_id'] = $processedResult['affiliate_id'] ?? null;
                    $result['is_retired_affiliate'] = $processedResult['is_retired_affiliate'] ?? false;
                    
                    Log::info("✅ Row {$rowCounter}: CREATED", [
                        'name' => ($row['First Name'] ?? '') . ' ' . ($row['Last Name'] ?? ''),
                        'email' => $row['Home Email'] ?? 'none',
                        'member_id' => $processedResult['member_id'] ?? null,
                        'user_id' => $processedResult['user_id'] ?? null
                    ]);
                    
                } elseif ($processedResult['status'] === 'updated') {
                    $updatedCount++;
                    $result['action'] = 'updated';
                    $result['message'] = $processedResult['message'] ?? 'Record updated successfully';
                    $result['record_id'] = $processedResult['member_id'] ?? $processedResult['user_id'] ?? null;
                    $result['member_id'] = $processedResult['member_id'] ?? null;
                    $result['user_id'] = $processedResult['user_id'] ?? null;
                    $result['affiliate_id'] = $processedResult['affiliate_id'] ?? null;
                    $result['is_retired_affiliate'] = $processedResult['is_retired_affiliate'] ?? false;
                    
                    Log::info("🔄 Row {$rowCounter}: UPDATED", [
                        'name' => ($row['First Name'] ?? '') . ' ' . ($row['Last Name'] ?? ''),
                        'email' => $row['Home Email'] ?? 'none'
                    ]);
                    
                } elseif ($processedResult['status'] === 'skipped') {
                    $skippedCount++;
                    $result['action'] = 'skipped';
                    $result['message'] = $processedResult['message'] ?? 'Record skipped';
                    
                    Log::info("⏭️ Row {$rowCounter}: SKIPPED", [
                        'name' => ($row['First Name'] ?? '') . ' ' . ($row['Last Name'] ?? ''),
                        'reason' => $processedResult['message'] ?? 'Unknown'
                    ]);
                    
                } else {
                    $errorCount++;
                    $result['action'] = 'failed';
                    $result['errors'] = $processedResult['errors'] ?? ['Unknown error'];
                    $result['message'] = 'Failed to process row';
                    
                    Log::warning("❌ Row {$rowCounter}: FAILED", [
                        'name' => ($row['First Name'] ?? '') . ' ' . ($row['Last Name'] ?? ''),
                        'errors' => $processedResult['errors'] ?? []
                    ]);
                }
                
                $result['status'] = $processedResult['status'];
                $successCount += in_array($processedResult['status'], ['created', 'updated']) ? 1 : 0;
                
            } catch (\Exception $e) {
                $errorCount++;
                $result['action'] = 'failed';
                $result['errors'] = [$e->getMessage()];
                $result['message'] = 'Exception occurred while processing row';
                $result['status'] = 'failed';
                
                Log::error("💥 Row {$rowCounter}: EXCEPTION", [
                    'name' => ($row['First Name'] ?? '') . ' ' . ($row['Last Name'] ?? ''),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }

            $rowResults[] = $result;
        }

        // Calculate duration and round to integer
        $duration = microtime(true) - $this->startTime;
        $roundedDuration = round($duration);
        
        // Log summary
        Log::info("=== CHUNK PROCESSING SUMMARY ===", [
            'chunk_id' => $chunkId,
            'total_rows_processed' => count($chunkData),
            'success_rows' => $successCount,
            'failed_rows' => $errorCount,
            'created_rows' => $createdCount,
            'updated_rows' => $updatedCount,
            'skipped_rows' => $skippedCount,
            'duration_seconds' => $roundedDuration,
            'rows_per_second' => $roundedDuration > 0 ? round(count($chunkData) / $roundedDuration, 2) : 0
        ]);
        
        // Save detailed row results
        $chunk->markAsCompleted(
            [
                'total_processed' => count($chunkData),
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'created_count' => $createdCount,
                'updated_count' => $updatedCount,
                'skipped_count' => $skippedCount,
            ],
            $rowResults,
            $roundedDuration
        );

        return [
            'chunk_id' => $chunkId,
            'total_rows' => count($chunkData),
            'success_rows' => $successCount,
            'failed_rows' => $errorCount,
            'created_rows' => $createdCount,
            'updated_rows' => $updatedCount,
            'skipped_rows' => $skippedCount,
            'row_results' => $rowResults,
            'duration_seconds' => $roundedDuration,
        ];
    }

    /**
     * Update immediate progress after each row (for real-time display)
     */
    private function updateImmediateProgress(string $importId, int $rowsProcessedInChunk, int $totalRowsInChunk): void
    {
        try {
            // Update cache for immediate UI feedback
            $cacheKey = "import_immediate_{$importId}";
            Cache::put($cacheKey, [
                'rows_processed_in_current_chunk' => $rowsProcessedInChunk,
                'total_rows_in_current_chunk' => $totalRowsInChunk,
                'chunk_progress_percentage' => $totalRowsInChunk > 0 
                    ? round(($rowsProcessedInChunk / $totalRowsInChunk) * 100, 2)
                    : 0,
                'timestamp' => now(),
            ], now()->addMinutes(5));
            
        } catch (\Exception $e) {
            Log::warning("Failed to update immediate progress: " . $e->getMessage());
        }
    }

    /**
     * Process a single CSV row
     */
    private function processRow(array $rowData, int $rowNumber): array
    {
        Log::info("Processing row {$rowNumber}", [
            'name' => ($rowData['First Name'] ?? '') . ' ' . ($rowData['Last Name'] ?? ''),
            'email' => $rowData['Home Email'] ?? 'none'
        ]);

        try {
            // Normalize the row data keys (remove BOM characters)
            $normalizedRowData = $this->normalizeRowKeys($rowData);
            
            // Debug: Log the normalized field names
            Log::debug("Normalized row {$rowNumber} field names", [
                'original_keys' => array_keys($rowData),
                'normalized_keys' => array_keys($normalizedRowData),
                'has_last_name' => isset($normalizedRowData['Last Name']),
                'has_first_name' => isset($normalizedRowData['First Name']),
                'has_affiliate' => isset($normalizedRowData['Affiliate'])
            ]);
            
            // Validate the record with normalized keys
            $this->validateRecord($normalizedRowData, $rowNumber);
            
            // Check if this is a test row or needs to be skipped
            if ($this->shouldSkipRow($normalizedRowData, $rowNumber)) {
                return [
                    'status' => 'skipped',
                    'message' => 'Row skipped (test or invalid data)'
                ];
            }
            
            // Process the record with normalized data
            $processedResult = $this->processRecord($normalizedRowData, $rowNumber, auth()->id() ?? 1);
            
            return $processedResult;
            
        } catch (\Exception $e) {
            Log::error("Error processing row {$rowNumber}: " . $e->getMessage(), [
                'row_data' => $this->sanitizeRowForLogging($rowData),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'status' => 'failed',
                'errors' => [$e->getMessage()],
                'message' => 'Exception occurred: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Normalize row keys - remove BOM characters and trim
     */
    private function normalizeRowKeys(array $rowData): array
    {
        $normalized = [];
        
        foreach ($rowData as $key => $value) {
            // Remove BOM characters and other invisible characters
            $cleanKey = $this->cleanString($key);
            
            // Map common variations to standard field names
            $cleanKey = $this->mapFieldName($cleanKey);
            
            $normalized[$cleanKey] = $value;
        }
        
        return $normalized;
    }

    /**
     * Clean string - remove BOM and invisible characters
     */
    private function cleanString(string $string): string
    {
        // Remove Byte Order Mark (BOM) and other invisible characters
        $boms = [
            "\xEF\xBB\xBF", // UTF-8 BOM
            "\xFE\xFF",     // UTF-16 BE BOM
            "\xFF\xFE",     // UTF-16 LE BOM
            "\x00\x00\xFE\xFF", // UTF-32 BE BOM
            "\xFF\xFE\x00\x00", // UTF-32 LE BOM
        ];
        
        foreach ($boms as $bom) {
            if (str_starts_with($string, $bom)) {
                $string = substr($string, strlen($bom));
                break;
            }
        }
        
        // Remove any other non-printable characters at the beginning
        $string = preg_replace('/^[\p{C}]+/u', '', $string);
        
        return trim($string);
    }

    /**
     * Map field names to standard names
     */
    private function mapFieldName(string $fieldName): string
    {
        $fieldName = strtolower(trim($fieldName));
        
        $fieldMappings = [
            // Last Name variations (including BOM-affected)
            'last name' => 'Last Name',
            'lastname' => 'Last Name',
            'last_name' => 'Last Name',
            'surname' => 'Last Name',
            'family name' => 'Last Name',
            '﻿last name' => 'Last Name', // BOM version
            
            // First Name variations
            'first name' => 'First Name',
            'firstname' => 'First Name',
            'first_name' => 'First Name',
            'given name' => 'First Name',
            'forename' => 'First Name',
            '﻿first name' => 'First Name', // BOM version
            
            // Email variations
            'home email' => 'Home Email',
            'homeemail' => 'Home Email',
            'home_email' => 'Home Email',
            'email' => 'Home Email',
            'e-mail' => 'Home Email',
            'personal email' => 'Home Email',
            '﻿home email' => 'Home Email', // BOM version
            
            // Affiliate variations
            'affiliate' => 'Affiliate',
            'chapter' => 'Affiliate',
            'branch' => 'Affiliate',
            'organization' => 'Affiliate',
            '﻿affiliate' => 'Affiliate', // BOM version
            
            // Other common field variations
            'membership id' => 'Membership ID',
            'membershipid' => 'Membership ID',
            'member_id' => 'Membership ID',
            'id' => 'Membership ID',
            '﻿membership id' => 'Membership ID', // BOM version
        ];
        
        return $fieldMappings[$fieldName] ?? ucfirst($fieldName);
    }

    private function validateRecord(array $record, int $rowNumber): void
    {
        // Debug: Log what fields we actually have
        Log::debug("Validating row {$rowNumber} fields", [
            'available_fields' => array_keys($record),
            'record_sample' => $this->sanitizeRowForLogging($record)
        ]);
        
        // Check if we have Last Name (try to find it)
        $hasLastName = false;
        $lastNameValue = null;
        $lastNameVariations = ['Last Name', 'LastName', 'Last_Name', 'Surname', 'Family Name'];
        
        foreach ($lastNameVariations as $field) {
            if (isset($record[$field]) && !empty(trim($record[$field] ?? ''))) {
                $hasLastName = true;
                $lastNameValue = trim($record[$field]);
                break;
            }
        }
        
        // Try more aggressive search for last name
        if (!$hasLastName) {
            foreach ($record as $key => $value) {
                $cleanKey = strtolower($this->cleanString($key));
                if (str_contains($cleanKey, 'last') || str_contains($cleanKey, 'surname') || str_contains($cleanKey, 'family')) {
                    if (!empty(trim($value))) {
                        $hasLastName = true;
                        $lastNameValue = trim($value);
                        break;
                    }
                }
            }
        }
        
        // REMOVED: Throw exception for missing Last Name
        // Instead, log warning and continue
        if (!$hasLastName) {
            Log::warning("Row {$rowNumber}: Missing Last Name field - proceeding with null", [
                'available_fields' => array_keys($record)
            ]);
        }
        
        // Check if we have First Name
        $hasFirstName = false;
        $firstNameValue = null;
        $firstNameVariations = ['First Name', 'FirstName', 'First_Name', 'Given Name', 'Forename'];
        
        foreach ($firstNameVariations as $field) {
            if (isset($record[$field]) && !empty(trim($record[$field] ?? ''))) {
                $hasFirstName = true;
                $firstNameValue = trim($record[$field]);
                break;
            }
        }
        
        // Try more aggressive search for first name
        if (!$hasFirstName) {
            foreach ($record as $key => $value) {
                $cleanKey = strtolower($this->cleanString($key));
                if (str_contains($cleanKey, 'first') || str_contains($cleanKey, 'given') || str_contains($cleanKey, 'forename')) {
                    if (!empty(trim($value))) {
                        $hasFirstName = true;
                        $firstNameValue = trim($value);
                        break;
                    }
                }
            }
        }
        
        // REMOVED: Throw exception for missing First Name
        // Instead, log warning and continue
        if (!$hasFirstName) {
            Log::warning("Row {$rowNumber}: Missing First Name field - proceeding with null", [
                'available_fields' => array_keys($record)
            ]);
        }
        
        // Check Affiliate - THIS IS OPTIONAL NOW
        $hasAffiliate = false;
        $affiliateValue = null;
        $affiliateVariations = ['Affiliate', 'Chapter', 'Branch', 'Organization'];
        
        foreach ($affiliateVariations as $field) {
            if (isset($record[$field]) && !empty(trim($record[$field] ?? ''))) {
                $hasAffiliate = true;
                $affiliateValue = trim($record[$field]);
                break;
            }
        }
        
        // Try more aggressive search for affiliate
        if (!$hasAffiliate) {
            foreach ($record as $key => $value) {
                $cleanKey = strtolower($this->cleanString($key));
                if (str_contains($cleanKey, 'affiliate') || str_contains($cleanKey, 'chapter') || 
                    str_contains($cleanKey, 'branch') || str_contains($cleanKey, 'org')) {
                    if (!empty(trim($value))) {
                        $hasAffiliate = true;
                        $affiliateValue = trim($value);
                        break;
                    }
                }
            }
        }
        
        // REMOVED: Throw exception for missing Affiliate
        // Affiliate is now optional
        if (!$hasAffiliate) {
            Log::info("Row {$rowNumber}: No affiliate specified - affiliate_id will be null");
        }
        
        // Optional: Validate email format if provided
        $emailField = $this->findEmailField($record);
        if ($emailField && !empty(trim($record[$emailField] ?? ''))) {
            $email = trim($record[$emailField]);
            if (!$this->dataCleaner->isValidEmail($email)) {
                // Don't throw for invalid email, just log warning
                Log::warning("Row {$rowNumber} has invalid email format: {$email}");
            }
        }
        
        // Log validation result
        Log::debug("Row {$rowNumber} validation completed", [
            'has_last_name' => $hasLastName,
            'has_first_name' => $hasFirstName,
            'has_affiliate' => $hasAffiliate,
            'last_name' => $hasLastName ? substr($lastNameValue ?? '', 0, 20) . '...' : 'MISSING',
            'first_name' => $hasFirstName ? substr($firstNameValue ?? '', 0, 20) . '...' : 'MISSING',
            'affiliate' => $hasAffiliate ? substr($affiliateValue ?? '', 0, 20) . '...' : 'NONE'
        ]);
    }

    /**
     * Find email field in record
     */
    private function findEmailField(array $record): ?string
    {
        $emailFields = ['Home Email', 'Email', 'E-mail', 'Personal Email', 'Work Email'];
        
        foreach ($emailFields as $field) {
            if (isset($record[$field])) {
                return $field;
            }
        }
        
        // Try case-insensitive search
        foreach ($record as $key => $value) {
            $cleanKey = strtolower($this->cleanString($key));
            if (str_contains($cleanKey, 'email') || str_contains($cleanKey, 'e-mail')) {
                return $key; // Return original key to preserve case
            }
        }
        
        return null;
    }

    /**
     * Determine if a row should be skipped
     */
    private function shouldSkipRow(array $rowData, int $rowNumber): bool
    {
        // Check if row has any data at all
        $hasData = false;
        foreach ($rowData as $value) {
            if (!empty(trim($value ?? ''))) {
                $hasData = true;
                break;
            }
        }
        
        if (!$hasData) {
            Log::info("Skipping row {$rowNumber} - empty row");
            return true;
        }
        
        // Skip test rows
        $firstName = $rowData['First Name'] ?? '';
        $lastName = $rowData['Last Name'] ?? '';
        
        if (stripos($firstName, 'test') !== false || stripos($lastName, 'test') !== false) {
            Log::info("Skipping test row {$rowNumber}");
            return true;
        }
        
        // Check for minimal required data
        $hasName = !empty(trim($firstName)) || !empty(trim($lastName));
        
        // Don't require affiliate for retired members
        if (!$hasName) {
            Log::info("Skipping row {$rowNumber} - insufficient data (no name)");
            return true;
        }
        
        return false;
    }

    private function processRecord(array $record, int $rowNumber, int $userId): array
    {
        Log::info("=== START processRecord row {$rowNumber} ===", [
            'email' => $record['Home Email'] ?? 'none',
            'name' => ($record['First Name'] ?? '') . ' ' . ($record['Last Name'] ?? '')
        ]);
        
        try {
            // Clean and normalize record
            $record = $this->dataCleaner->cleanRecord($record);
            
            // Extract and clean data using normalized field names
            // Handle missing fields gracefully
            $firstName = isset($record['First Name']) ? $this->dataCleaner->cleanName($record['First Name']) : null;
            $lastName = isset($record['Last Name']) ? $this->dataCleaner->cleanName($record['Last Name']) : null;
            $homeEmail = isset($record['Home Email']) ? $this->dataCleaner->cleanEmail($record['Home Email']) : null;
            $affiliateName = $record['Affiliate'] ?? null;
            
            // Process affiliate - may return null
            $affiliate = null;
            $affiliateId = null;
            
            if ($affiliateName && !empty(trim($affiliateName))) {
                $affiliate = $this->processAffiliate($affiliateName);
                $affiliateId = $affiliate?->id;
            } else {
                Log::info("Row {$rowNumber}: No affiliate name provided - affiliate_id will be null");
            }
            
            Log::info("Creating/updating user for row {$rowNumber}", [
                'name' => ($firstName ?? 'Unknown') . ' ' . ($lastName ?? 'Unknown'),
                'email' => $homeEmail ?: 'null',
                'affiliate' => $affiliateName ?: 'NONE',
                'affiliate_id' => $affiliateId
            ]);
            
            // Create or update user (handle null names)
            $user = $this->userImportService->createOrUpdateUser(
                $homeEmail,
                $firstName ?? 'Unknown',
                $lastName ?? 'Unknown',
                $affiliateName,
                $userId,
                $record
            );
            
            // VERIFY USER IN DATABASE
            $dbUser = DB::table('users')->where('id', $user->id)->first();
            Log::info("User verification row {$rowNumber}", [
                'user_id' => $user->id,
                'in_database' => $dbUser ? 'YES' : 'NO',
                'email' => $user->email,
                'name' => $user->name
            ]);
            
            if (!$dbUser) {
                throw new \Exception("User {$user->id} not found in database after creation!");
            }
            
            Log::info("Creating/updating member for row {$rowNumber}", [
                'user_id' => $user->id,
                'affiliate_id' => $affiliateId
            ]);
            
            // Create or update member
            $member = $this->memberService->createOrUpdateMember(
                $user->id,
                $record,
                $affiliateId, // Can be null
                $userId
            );
            
            // VERIFY MEMBER IN DATABASE
            $dbMember = DB::table('members')->where('id', $member->id)->first();
            Log::info("Member verification row {$rowNumber}", [
                'member_id' => $member->id,
                'in_database' => $dbMember ? 'YES' : 'NO',
                'user_id' => $member->user_id,
                'work_email' => $member->work_email
            ]);
            
            if (!$dbMember) {
                throw new \Exception("Member {$member->id} not found in database after creation!");
            }
            
            // Assign roles
            $assignedRoles = $this->roleAssignmentService->assignRoles(
                $user,
                $member,
                $record,
                $affiliateId,
                $userId
            );
            
            Log::info("=== SUCCESS processRecord row {$rowNumber} ===", [
                'user_id' => $user->id,
                'member_id' => $member->id,
                'roles_count' => count($assignedRoles),
                'email' => $user->email ?: 'null'
            ]);
            
            return [
                'status' => 'created',
                'user_id' => $user->id,
                'member_id' => $member->id,
                'message' => 'Record processed successfully',
                'affiliate_id' => $affiliateId,
                'is_retired_affiliate' => false // Not retired, just missing
            ];
            
        } catch (\Exception $e) {
            Log::error("=== FAILED processRecord row {$rowNumber} ===", [
                'error' => $e->getMessage(),
                'name' => ($record['First Name'] ?? '') . ' ' . ($record['Last Name'] ?? '')
            ]);
            throw $e;
        }
    }

    /**
     * Process affiliate - returns null for empty/missing affiliate
     */
    private function processAffiliate(string $affiliateName): ?Affiliate
    {
        // If affiliate name is empty, return null
        if (empty(trim($affiliateName))) {
            return null;
        }
        
        // If affiliate name contains "RETIRED", return null
        if (stripos($affiliateName, 'RETIRED') !== false) {
            Log::info("Retired affiliate detected: {$affiliateName} - setting affiliate_id to null");
            return null;
        }
        
        $cleanName = $this->dataCleaner->formatAffiliateName($affiliateName);
        
        // If after cleaning, it's still empty, return null
        if (empty(trim($cleanName))) {
            return null;
        }
        
        try {
            return Affiliate::firstOrCreate(
                ['name' => $cleanName],
                [
                    'created_by' => 1, // System user
                    'updated_by' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        } catch (\Exception $e) {
            Log::warning("Failed to create affiliate '{$cleanName}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * Sanitize row data for logging (remove sensitive info)
     */
    private function sanitizeRowForLogging(array $row): array
    {
        $sanitized = [];
        foreach ($row as $key => $value) {
            // Clean the key first
            $cleanKey = $this->cleanString($key);
            
            // Mask email addresses
            if (stripos($cleanKey, 'email') !== false && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $parts = explode('@', $value);
                if (count($parts) === 2) {
                    $sanitized[$cleanKey] = substr($parts[0], 0, 2) . '***@' . $parts[1];
                } else {
                    $sanitized[$cleanKey] = '***@***';
                }
            } 
            // Mask phone numbers
            elseif (stripos($cleanKey, 'phone') !== false) {
                $sanitized[$cleanKey] = preg_replace('/\d/', '*', $value);
            }
            // Truncate long values
            elseif (strlen($value) > 50) {
                $sanitized[$cleanKey] = substr($value, 0, 50) . '...';
            }
            else {
                $sanitized[$cleanKey] = $value;
            }
        }
        return $sanitized;
    }

    /**
     * Set processing options
     */
    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    /**
     * Get processing options
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Reset start time
     */
    public function resetStartTime(): void
    {
        $this->startTime = microtime(true);
    }

    /**
     * Get detailed row results for a chunk
     */
    public function getRowResults(string $chunkId, array $filters = []): array
    {
        $chunk = CsvImportChunk::findOrFail($chunkId);
        $results = $chunk->row_results ?? [];
        
        if (!empty($filters)) {
            $results = $this->filterResults($results, $filters);
        }
        
        return [
            'chunk_id' => $chunkId,
            'total_rows' => count($results),
            'results' => $results,
            'summary' => $this->calculateSummary($results)
        ];
    }

    /**
     * Filter results based on criteria
     */
    private function filterResults(array $results, array $filters): array
    {
        return array_filter($results, function ($result) use ($filters) {
            foreach ($filters as $key => $value) {
                if ($key === 'action' && ($result['action'] ?? '') !== $value) {
                    return false;
                }
                if ($key === 'status' && ($result['status'] ?? '') !== $value) {
                    return false;
                }
                if ($key === 'has_errors' && $value && empty($result['errors'] ?? [])) {
                    return false;
                }
            }
            return true;
        });
    }

    /**
     * Calculate summary statistics
     */
    private function calculateSummary(array $results): array
    {
        $summary = [
            'total' => count($results),
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'with_errors' => 0
        ];
        
        foreach ($results as $result) {
            $action = $result['action'] ?? 'unknown';
            if (isset($summary[$action])) {
                $summary[$action]++;
            }
            
            if (!empty($result['errors'] ?? [])) {
                $summary['with_errors']++;
            }
        }
        
        return $summary;
    }

    /**
     * Get chunk statistics
     */
    public function getChunkStatistics(string $chunkId): array
    {
        $chunk = CsvImportChunk::findOrFail($chunkId);
        
        return [
            'chunk_id' => $chunk->id,
            'import_id' => $chunk->import_id,
            'chunk_index' => $chunk->chunk_index,
            'status' => $chunk->status,
            'total_rows' => $chunk->total_rows,
            'processed_rows' => $chunk->processed_rows,
            'created_rows' => $chunk->created_rows,
            'updated_rows' => $chunk->updated_rows,
            'skipped_rows' => $chunk->skipped_rows,
            'failed_rows' => $chunk->failed_rows,
            'success_rows' => $chunk->success_rows,
            'processing_duration' => $chunk->processing_duration,
            'started_at' => $chunk->started_at,
            'completed_at' => $chunk->completed_at,
            'row_results_count' => count($chunk->row_results ?? [])
        ];
    }
}