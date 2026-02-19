<?php

namespace App\Services\CsvImport;

use Illuminate\Support\Facades\Log;

class DataCleanerService
{
    private array $roleVariations = [
        'access_level_mappings' => [
            'national_administrator' => ['national administrator', 'national admin', 'nat admin'],
            'org_executive_commitee' => ['nso executive committee', 'nso executive', 'executive committee'],
            'org_research_commitee' => ['nso research committee', 'nso research', 'research committee'],
        ],
        
        'officer_position_keywords' => [
            'president', 'vice president', 'secretary', 'treasurer',
            'grievance chair', 'bargaining chair', 'communications committee chair',
            'health insurance committee chair', '401k committee chair',
            'pension committee chair', 'membership chair', 'chair', 'co-chair',
            'delegate', 'representative', 'director', 'coordinator', 'organizer',
            'leader', 'officer', 'board member', 'executive', 'committee'
        ],
        
        'non_officer_positions' => ['member', 'staff', 'employee', 'worker', 'associate', 'regular']
    ];

    /**
     * Clean and normalize entire record
     */
    public function cleanRecord(array $record): array
    {
        $cleaned = [];
        
        foreach ($record as $key => $value) {
            $cleaned[$this->cleanString($key)] = $this->cleanString($value);
        }
        
        return $cleaned;
    }

    /**
     * Clean string by removing BOM and trimming
     */
    public function cleanString(string $string): string
    {
        // Remove UTF-8 BOM
        $string = str_replace("\xEF\xBB\xBF", '', $string);
        
        // Remove UTF-16 LE BOM
        $string = str_replace("\xFF\xFE", '', $string);
        
        // Remove UTF-16 BE BOM
        $string = str_replace("\xFE\xFF", '', $string);
        
        // Remove UTF-32 LE BOM
        $string = str_replace("\xFF\xFE\x00\x00", '', $string);
        
        // Remove UTF-32 BE BOM
        $string = str_replace("\x00\x00\xFE\xFF", '', $string);
        
        return trim($string);
    }

    /**
     * Clean name
     */
    public function cleanName(string $name): string
    {
        $name = $this->cleanString($name);
        $name = str_replace(['"', "'"], '', $name);
        $name = rtrim($name, ', ');
        
        return ucwords(strtolower($name));
    }

    /**
     * Clean email
     */
    public function cleanEmail(string $email): ?string
    {
        $email = $this->cleanString($email);
        
        if (empty($email) || $email === 'no email') {
            return null;
        }
        
        $email = strtolower($email);
        
        // Check for phone numbers in email field
        if (preg_match('/\(\d{3}\)\s*\d{3}-\d{4}/', $email) || 
            preg_match('/\d{10}/', preg_replace('/[^\d]/', '', $email))) {
            return null;
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        
        return $email;
    }

    /**
     * Clean phone number - NEW METHOD ADDED
     */
    public function cleanPhone(string $phone): ?string
    {
        $phone = $this->cleanString($phone);
        
        if (empty($phone) || strtolower($phone) === 'no phone' || strtolower($phone) === 'n/a') {
            return null;
        }
        
        // Remove all non-numeric characters except plus sign
        $digits = preg_replace('/[^\d\+]/', '', $phone);
        
        // Check if it's empty after cleaning
        if (empty($digits)) {
            return null;
        }
        
        // Format the phone number
        return $this->formatPhoneNumber($phone);
    }

    /**
     * Check if email is valid
     */
    public function isValidEmail(?string $email): bool
    {
        if ($email === null || empty(trim($email))) {
            return false;
        }
        
        $email = trim($email);
        
        // Check common invalid patterns
        if (strtolower($email) === 'no email' || 
            strtolower($email) === 'n/a' ||
            strtolower($email) === 'none') {
            return false;
        }
        
        // Check if it's clearly a phone number
        if (preg_match('/\(\d{3}\)\s*\d{3}-\d{4}/', $email) || 
            preg_match('/^\d{3}-\d{3}-\d{4}$/', $email) ||
            preg_match('/^\d{10}$/', preg_replace('/[^\d]/', '', $email))) {
            return false;
        }
        
        // Check if it has @ symbol (basic email check)
        if (strpos($email, '@') === false) {
            return false;
        }
        
        // Final validation
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Check if .org email is restricted for the given affiliate
     */
    public function isOrgEmailRestricted(string $email, ?string $affiliateName): bool
    {
        $allowedOrgEmailAffiliates = [
            'CALIFORNIA ASO',
            'MICHIGAN MESSA SSA', 
            'VIRGINIA ASO'
        ];
        
        // Check if email ends with .org
        if (strtolower(substr($email, -4)) !== '.org') {
            return false;
        }
        
        // If no affiliate name, restrict .org emails
        if (empty($affiliateName)) {
            return true;
        }
        
        // Normalize affiliate name for comparison
        $normalizedAffiliate = strtoupper(trim($affiliateName));
        
        // Check if affiliate is in the allowed list
        foreach ($allowedOrgEmailAffiliates as $allowedAffiliate) {
            if (strpos($normalizedAffiliate, strtoupper($allowedAffiliate)) !== false ||
                strpos(strtoupper($allowedAffiliate), $normalizedAffiliate) !== false) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Format affiliate name
     */
    public function formatAffiliateName(string $name): string
    {
        $name = $this->cleanString($name);
        
        if (empty($name)) {
            return 'Unknown Affiliate';
        }
        
        // Handle special cases and acronyms
        return $this->formatAffiliateNameLogic($name);
    }

    /**
     * Logic for formatting affiliate name
     */
    private function formatAffiliateNameLogic(string $name): string
    {
        $threeLetterAcronyms = [
            'ASO', 'ASU', 'SO', 'FSR', 'PSU', 'PSA', 'SSA', 'FSO', 'NEA', 'SEA', 
            'NEO', 'SEO', 'NLR', 'SLR', 'CSO', 'ORG', 'HOA', 'POA', 'BOA', 'ROA',
            'CWA', 'UFC', 'IBT', 'IBEW', 'UAW', 'AFT', 'SEIU', 'NEA', 'AFSCME'
        ];
        
        $specialCases = [
            'NEA-AFT', 'AFL-CIO', 'UFCW', 'IBEW', 'SEIU', 'AFSCME'
        ];
        
        $upperName = strtoupper($name);
        foreach ($specialCases as $special) {
            if (strtoupper($special) === $upperName) {
                return $special;
            }
        }
        
        $parts = preg_split('/\s+-\s+|\s+&\s+|\s+\/\s+|\s+/', $name);
        $formattedParts = [];
        
        foreach ($parts as $part) {
            $trimmedPart = trim($part);
            
            if (empty($trimmedPart)) continue;
            
            // Handle numbers
            if (preg_match('/^\d+$/', $trimmedPart)) {
                $formattedParts[] = $trimmedPart;
                continue;
            }
            
            // Handle special words
            if (strtolower($trimmedPart) === 'new') {
                $formattedParts[] = 'New';
                continue;
            }
            
            // Handle 3-letter acronyms
            $isThreeLetter = strlen($trimmedPart) === 3;
            $isAcronym = in_array(strtoupper($trimmedPart), $threeLetterAcronyms);
            
            if ($isThreeLetter && $isAcronym) {
                $formattedParts[] = strtoupper($trimmedPart);
            } elseif ($isThreeLetter) {
                $formattedParts[] = ucfirst(strtolower($trimmedPart));
            } else {
                if (strtoupper($trimmedPart) === $trimmedPart && 
                    preg_match('/^[A-Z]{2,}$/', $trimmedPart) && 
                    strlen($trimmedPart) <= 5) {
                    $formattedParts[] = $trimmedPart;
                } else {
                    $formattedParts[] = ucwords(strtolower($trimmedPart));
                }
            }
        }
        
        $result = implode(' ', $formattedParts);
        
        // Restore hyphens
        if (strpos($name, '-') !== false) {
            $result = preg_replace('/\s+-\s+/', '-', $result);
            $result = preg_replace('/\s-\s/', '-', $result);
        }
        
        return $result;
    }

    /**
     * Clean membership ID
     */
    public function cleanMembershipId(string $memberId): ?string
    {
        $memberId = $this->cleanString($memberId);
        
        if (empty($memberId)) {
            return null;
        }
        
        // Membership IDs should be 8 characters (2 letters + 6 digits)
        if (preg_match('/^[A-Z]{2}\d{6}$/', $memberId)) {
            return $memberId;
        }
        
        // If it doesn't match pattern, return as-is for manual review
        return $memberId;
    }

    /**
     * Clean member level
     */
    public function cleanMemberLevel(string $level): ?string
    {
        $level = $this->cleanString($level);
        
        if (empty($level)) {
            return null;
        }
        
        $upperLevel = strtoupper($level);
        
        if ($upperLevel === 'ASSOCIATE' || $upperLevel === 'ASSOC' || $upperLevel === 'ASSO') {
            return 'Associate';
        }
        
        if ($upperLevel === 'PROFESSIONAL' || $upperLevel === 'PROF' || $upperLevel === 'PRO') {
            return 'Professional';
        }
        
        return null;
    }

    /**
     * Clean member status
     */
    public function cleanMemberStatus(string $status): ?string
    {
        $status = $this->cleanString($status);
        
        if (empty($status)) {
            return null;
        }
        
        // Check for dates or numbers (reject)
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}$/', $status) || preg_match('/\d/', $status)) {
            return null;
        }
        
        $lowerStatus = strtolower($status);
        
        // Handle special cases first
        if ($lowerStatus === 'cso') return 'CSO';
        if ($lowerStatus === 'hq') return 'HQ';
        if ($lowerStatus === 'mb') return 'MB';
        
        // Handle combined statuses
        if (strpos($lowerStatus, 'active') !== false && strpos($lowerStatus, 'ra delegate') !== false) {
            return 'Active - RA Delegate';
        }
        if (strpos($lowerStatus, 'cso affiliate') !== false) {
            return 'CSO Affiliate';
        }
        if (strpos($lowerStatus, 'contract for service') !== false) {
            return 'Contract for Service';
        }
        if (strpos($lowerStatus, 'permanent field organizer') !== false) {
            return 'Permanent Field Organizer';
        }
        if (strpos($lowerStatus, 'associate staff') !== false) {
            return 'Associate Staff';
        }
        
        // Standardize common variations
        $statusMap = [
            'active' => 'Active',
            'actv' => 'Active',
            'act' => 'Active',
            'retired' => 'Retired',
            'retierd' => 'Retired',
            'retrd' => 'Retired',
            'full-time' => 'Full-time',
            'full time' => 'Full-time',
            'fulltime' => 'Full-time',
            'part-time' => 'Part-time',
            'part time' => 'Part-time',
            'parttime' => 'Part-time',
            'contract' => 'Contract',
            'cntrct' => 'Contract',
            'temp' => 'Temporary',
            'temporary' => 'Temporary',
            'permanent' => 'Permanent',
            'perm' => 'Permanent',
        ];
        
        if (isset($statusMap[$lowerStatus])) {
            return $statusMap[$lowerStatus];
        }
        
        // Default: Capitalize first letter of each word
        $cleaned = ucwords($lowerStatus);
        
        // Validate against allowed values
        $allowedStatuses = [
            'Active', 'Retired', 'Full-time', 'Part-time', 'Contract', 
            'Temporary', 'Permanent', 'CSO', 'HQ', 'MB', 'On Leave',
            'Inactive', 'CSO Affiliate', 'Contract for Service',
            'Permanent Field Organizer', 'Associate Staff', 'Active - RA Delegate'
        ];
        
        if (!in_array($cleaned, $allowedStatuses)) {
            Log::warning("Unrecognized status value: {$status} cleaned to: {$cleaned}");
            return null;
        }
        
        return $cleaned;
    }

    /**
     * Clean employment status
     */
public function cleanEmploymentStatus(string $empStatus): ?string
{
    $original = $empStatus;
    $empStatus = $this->cleanString($empStatus);
    
    Log::debug("cleanEmploymentStatus called", [
        'original' => $original,
        'after_cleanString' => $empStatus,
        'is_empty' => empty($empStatus)
    ]);
    
    if (empty($empStatus)) {
        Log::debug("Employment status is empty, returning null");
        return null;
    }
    
    $lowerStatus = strtolower($empStatus);
    
    Log::debug("Processing employment status", [
        'lower_status' => $lowerStatus,
        'original_case' => $empStatus
    ]);
    
    // Check if it's actually a department code
    $departmentCodes = ['r4-hr', 'r2', 'r3-po', 'legal', 'comms', 'issd'];
    if (in_array($lowerStatus, $departmentCodes)) {
        Log::warning("Department code found in employment_status: {$empStatus}");
        return null;
    }
    
    // Standardize variations
    if ($lowerStatus === 'full time') {
        Log::debug("Matched 'full time' exactly, returning 'Full Time'");
        return 'Full Time';
    }
    
    if ($lowerStatus === 'part time') {
        Log::debug("Matched 'part time' exactly, returning 'Part Time'");
        return 'Part Time';
    }
    
    if (strpos($lowerStatus, 'full') !== false && strpos($lowerStatus, 'time') !== false) {
        Log::debug("Found 'full' and 'time' in string, returning 'Full Time'");
        return 'Full Time';
    }
    
    if (strpos($lowerStatus, 'part') !== false && strpos($lowerStatus, 'time') !== false) {
        Log::debug("Found 'part' and 'time' in string, returning 'Part Time'");
        return 'Part Time';
    }
    
    $statusMap = [
        'ft' => 'Full Time',
        'full' => 'Full Time',
        'full-t' => 'Full Time',
        'full t' => 'Full Time',
        'pt' => 'Part Time',
        'part' => 'Part Time',
        'part-t' => 'Part Time',
        'part t' => 'Part Time',
        'full-time' => 'Full Time',
        'part-time' => 'Part Time',
        'fulltime' => 'Full Time',
        'parttime' => 'Part Time',
    ];
    
    if (isset($statusMap[$lowerStatus])) {
        Log::debug("Matched in statusMap", [
            'lower_status' => $lowerStatus,
            'mapped_to' => $statusMap[$lowerStatus]
        ]);
        return $statusMap[$lowerStatus];
    }
    
    Log::debug("No match found for employment status", [
        'lower_status' => $lowerStatus,
        'original' => $original
    ]);
    
    return null;
}

    /**
     * Clean self ID
     */
    public function cleanSelfId(string $selfId): ?string
    {
        $selfId = $this->cleanString($selfId);
        
        if (empty($selfId) || strtolower($selfId) === 'no information provided') {
            return 'I choose not to identify';
        }
        
        return $selfId;
    }

    /**
     * Format phone number
     */
// In DataCleanerService.php, update the formatPhoneNumber method:
public function formatPhoneNumber(string $phone): ?string
{
    $originalPhone = $phone;
    $phone = $this->cleanString($phone);
    
    Log::debug("formatPhoneNumber called:", [
        'original' => $originalPhone,
        'after_cleanString' => $phone,
        'is_empty' => empty($phone)
    ]);
    
    if (empty($phone)) {
        Log::debug("Phone is empty after cleaning, returning null");
        return null;
    }
    
    // Remove all non-numeric characters
    $digits = preg_replace('/[^\d]/', '', $phone);
    
    Log::debug("Phone number processing:", [
        'original_phone' => $phone,
        'digits_only' => $digits,
        'digit_count' => strlen($digits)
    ]);
    
    // Check if we have enough digits
    if (strlen($digits) === 10) {
        $formatted = '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6, 4);
        Log::debug("Formatted as 10-digit US number", ['formatted' => $formatted]);
        return $formatted;
    } elseif (strlen($digits) === 11 && $digits[0] === '1') {
        $formatted = '1 (' . substr($digits, 1, 3) . ') ' . substr($digits, 4, 3) . '-' . substr($digits, 7, 4);
        Log::debug("Formatted as 11-digit US number with country code", ['formatted' => $formatted]);
        return $formatted;
    }
    
    Log::debug("Phone doesn't match expected format, returning as-is", [
        'phone' => $phone,
        'digits' => $digits,
        'expected' => '10 or 11 digits'
    ]);
    return $phone;
}

    /**
     * Format state
     */
    public function formatState(string $state): ?string
    {
        $state = $this->cleanString($state);
        
        if (empty($state)) {
            return null;
        }
        
        $stateAbbreviations = [
            'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
            'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
            'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
            'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
            'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
            'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
            'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
            'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
            'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
            'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
            'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
            'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
            'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'DC' => 'District of Columbia',
        ];
        
        // Check if it's a state abbreviation (2 letters)
        if (strlen($state) === 2 && ctype_alpha($state)) {
            $abbreviation = strtoupper($state);
            if (isset($stateAbbreviations[$abbreviation])) {
                return $stateAbbreviations[$abbreviation];
            }
        }
        
        // If it's already a state name, ensure proper case
        $lowerState = strtolower($state);
        foreach ($stateAbbreviations as $abbr => $fullName) {
            if (strtolower($fullName) === $lowerState) {
                return $fullName;
            }
        }
        
        return $state;
    }

    /**
     * Clean address
     */
    public function cleanAddress(string $address): ?string
    {
        $address = $this->cleanString($address);
        
        if (empty($address)) {
            return null;
        }
        
        return $address;
    }

    /**
     * Clean city
     */
    public function cleanCity(string $city): ?string
    {
        $city = $this->cleanString($city);
        
        if (empty($city)) {
            return null;
        }
        
        $city = trim($city);
        
        // Fix common misspellings
        $corrections = [
            'chicagp' => 'Chicago',
            'philedelphia' => 'Philadelphia',
            'los angelas' => 'Los Angeles',
            'san fransisco' => 'San Francisco',
        ];
        
        $lowerCity = strtolower($city);
        if (isset($corrections[$lowerCity])) {
            return $corrections[$lowerCity];
        }
        
        return ucwords(strtolower($city));
    }

    /**
     * Clean zip code
     */
    public function cleanZipCode(string $zip): ?string
    {
        $zip = $this->cleanString($zip);
        
        if (empty($zip)) {
            return null;
        }
        
        return $zip;
    }

    /**
     * Get role variations configuration
     */
    public function getRoleVariations(): array
    {
        return $this->roleVariations;
    }

    /**
     * Match role by access level
     */
    public function matchRoleByAccessLevel(string $accessLevel): ?\App\Models\Role
    {
        $accessLevel = strtolower(trim($accessLevel));
        
        // Skip "affiliate administrator"
        if (strpos($accessLevel, 'affiliate administrator') !== false || 
            strpos($accessLevel, 'affiliate admin') !== false) {
            return null;
        }
        
        // Get all roles from database
        $allRoles = \App\Models\Role::all();
        
        // First: Try exact or partial match
        foreach ($allRoles as $role) {
            $roleName = strtolower($role->name);
            
            if ($roleName === $accessLevel) {
                return $role;
            }
            
            if (strpos($roleName, $accessLevel) !== false || 
                strpos($accessLevel, $roleName) !== false) {
                return $role;
            }
            
            // Match with common variations
            if (isset($this->roleVariations['access_level_mappings'][$roleName])) {
                foreach ($this->roleVariations['access_level_mappings'][$roleName] as $variation) {
                    if (strpos($accessLevel, $variation) !== false || 
                        strpos($variation, $accessLevel) !== false) {
                        return $role;
                    }
                }
            }
        }
        
        // Second: Try fuzzy matching
        $bestMatch = null;
        $bestScore = PHP_INT_MAX;
        
        foreach ($allRoles as $role) {
            $roleName = strtolower($role->name);
            $distance = levenshtein($accessLevel, $roleName);
            
            $maxDistance = ceil(max(strlen($accessLevel), strlen($roleName)) * 0.3);
            
            if ($distance <= $maxDistance && $distance < $bestScore) {
                $bestScore = $distance;
                $bestMatch = $role;
            }
        }
        
        return $bestMatch;
    }

    /**
     * Check if position is officer position
     */
    public function isOfficerPosition(string $position): bool
    {
        $position = strtolower(trim($position));
        
        // Skip if it's clearly not an officer
        foreach ($this->roleVariations['non_officer_positions'] as $nonOfficer) {
            if ($position === $nonOfficer || strpos($position, $nonOfficer) !== false) {
                return false;
            }
        }
        
        // Check for officer keywords
        foreach ($this->roleVariations['officer_position_keywords'] as $keyword) {
            if (strpos($position, $keyword) !== false) {
                return true;
            }
        }
        
        // Check if position contains position indicators
        $positionIndicators = ['chair', 'president', 'secretary', 'treasurer', 'director', 'coordinator'];
        foreach ($positionIndicators as $indicator) {
            if (strpos($position, $indicator) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if we should skip validation (relaxed mode) - NEW METHOD ADDED
     */
    public function shouldSkipValidation(array $options): bool
    {
        return ($options['validation_strictness'] ?? 'strict') === 'relaxed';
    }

    /**
     * Clean string with optional validation skipping - NEW METHOD ADDED
     */
    public function cleanStringRelaxed(string $value, array $options = []): string
    {
        if ($this->shouldSkipValidation($options)) {
            return trim($value);
        }
        return $this->cleanString($value);
    }
}