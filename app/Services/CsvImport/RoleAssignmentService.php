<?php

namespace App\Services\CsvImport;

use App\Models\Role;
use App\Models\UserRole;
use App\Models\OrganizationRole;
use App\Models\MemberOrganizationRole;
use App\Models\OfficerPosition;
use App\Models\AffiliateOfficer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class RoleAssignmentService
{
    private DataCleanerService $dataCleaner;
    
    private string $defaultRoleName = 'affiliate_member';
    private string $affiliateOfficerRoleName = 'affiliate_officer';
    private string $affiliateAdminRoleName = 'affiliate_administrator';
    
    public function __construct(DataCleanerService $dataCleaner)
    {
        $this->dataCleaner = $dataCleaner;
    }

    /**
     * Assign roles to user and member
     */
    public function assignRoles(
        $user,
        $member,
        array $record,
        ?int $affiliateId,
        int $userId
    ): array {
        $assignedRoles = [
            'user_roles' => [],
            'national_roles' => [],
            'positions' => []
        ];
        
        // STEP 1: Always assign default affiliate_member role
        $defaultRole = Role::where('name', $this->defaultRoleName)->first();
        if ($defaultRole) {
            $this->assignRoleToUser($user->id, $defaultRole->id);
            $assignedRoles['user_roles'][] = $this->defaultRoleName;
        } else {
            Log::error("Default role '{$this->defaultRoleName}' not found in database");
        }
        
        // STEP 2: Check Access Level for national roles
        if (!empty($record['Access Level'])) {
            $accessLevel = $this->dataCleaner->cleanString($record['Access Level']);
            
            // Skip "Affiliate Administrator" access level
            if (stripos($accessLevel, 'affiliate administrator') !== false) {
                Log::debug("Skipping 'Affiliate Administrator' access level per client request", [
                    'user_id' => $user->id,
                    'access_level' => $accessLevel
                ]);
            } else {
                $matchedRole = $this->dataCleaner->matchRoleByAccessLevel($accessLevel);
                
                if ($matchedRole) {
                    $this->assignRoleToUser($user->id, $matchedRole->id);
                    $assignedRoles['user_roles'][] = $matchedRole->name;
                    Log::debug("Assigned role from Access Level", [
                        'user_id' => $user->id,
                        'role_id' => $matchedRole->id,
                        'role_name' => $matchedRole->name,
                        'access_level' => $accessLevel
                    ]);
                }
            }
            
            // Also handle national roles for member
            $nationalRolesAssigned = $this->handleAccessLevel($member, $record['Access Level']);
            $assignedRoles['national_roles'] = $nationalRolesAssigned;
        }
        
        // STEP 3: Check Position for affiliate_officer role
        if (!empty($record['Position'])) {
            $position = $this->dataCleaner->cleanString($record['Position']);
            $isOfficer = $this->dataCleaner->isOfficerPosition($position);
            
            if ($isOfficer) {
                $officerRole = Role::where('name', $this->affiliateOfficerRoleName)->first();
                if ($officerRole) {
                    $this->assignRoleToUser($user->id, $officerRole->id);
                    $assignedRoles['user_roles'][] = $this->affiliateOfficerRoleName;
                    Log::debug("Assigned affiliate_officer role based on position", [
                        'user_id' => $user->id,
                        'position' => $position
                    ]);
                }
            }
            
            // Also handle officer positions
            if ($affiliateId) {
                $positionsAssigned = $this->handlePosition($member, $record['Position'], $affiliateId);
                $assignedRoles['positions'] = $positionsAssigned;
            }
        }
        
        return $assignedRoles;
    }

    /**
     * Handle Access Level (Organization Roles)
     */
    private function handleAccessLevel($member, $accessLevel): array
    {
        $accessLevel = $this->dataCleaner->cleanString($accessLevel);
        
        if (empty($accessLevel)) {
            return [];
        }
        
        // Get all national roles
        $nationalRoles = OrganizationRole::all();
        $assignedRoles = [];
        
        // Try to match by name
        foreach ($nationalRoles as $role) {
            $roleName = strtolower($role->name);
            $cleanAccessLevel = strtolower($accessLevel);
            
            // Exact match
            if ($roleName === $cleanAccessLevel) {
                MemberOrganizationRole::updateOrCreate(
                    [
                        'member_id' => $member->id,
                        'role_id' => $role->id,
                    ],
                    [
                        'created_by' => Auth::id(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
                $assignedRoles[] = $role->name;
                break;
            }
            
            // Partial match
            if (strpos($cleanAccessLevel, $roleName) !== false || 
                strpos($roleName, $cleanAccessLevel) !== false) {
                MemberOrganizationRole::updateOrCreate(
                    [
                        'member_id' => $member->id,
                        'role_id' => $role->id,
                    ],
                    [
                        'created_by' => Auth::id(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
                $assignedRoles[] = $role->name;
                break;
            }
        }
        
        // If no match found, try fuzzy matching
        if (empty($assignedRoles)) {
            $bestMatch = null;
            $bestScore = PHP_INT_MAX;
            
            foreach ($nationalRoles as $role) {
                $roleName = strtolower($role->name);
                $cleanAccessLevel = strtolower($accessLevel);
                $distance = levenshtein($cleanAccessLevel, $roleName);
                
                $maxDistance = ceil(max(strlen($cleanAccessLevel), strlen($roleName)) * 0.4);
                
                if ($distance <= $maxDistance && $distance < $bestScore) {
                    $bestScore = $distance;
                    $bestMatch = $role;
                }
            }
            
            if ($bestMatch) {
                MemberOrganizationRole::updateOrCreate(
                    [
                        'member_id' => $member->id,
                        'role_id' => $bestMatch->id,
                    ],
                    [
                        'created_by' => Auth::id(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
                $assignedRoles[] = $bestMatch->name;
                Log::debug("Fuzzy matched national role", [
                    'access_level' => $accessLevel,
                    'matched_role' => $bestMatch->name,
                    'distance' => $bestScore
                ]);
            }
        }
        
        return $assignedRoles;
    }

    /**
     * Handle Position (Affiliate Officer Roles)
     */
    private function handlePosition($member, $position, $affiliateId): array
    {
        $position = $this->dataCleaner->cleanString($position);
        
        if (empty($position)) {
            return [];
        }
        
        // Split combined positions
        $positions = preg_split('/\s*\+\s*|\s*&\s*|\s*,\s*/', $position);
        
        $assignedPositions = [];
        
        foreach ($positions as $singlePosition) {
            $singlePosition = trim($singlePosition);
            
            if (empty($singlePosition) || strcasecmp($singlePosition, 'Member') === 0) {
                continue;
            }
            
            // Try to find existing position by name (case-insensitive)
            $officerPosition = OfficerPosition::whereRaw('LOWER(name) = ?', [strtolower($singlePosition)])->first();
            
            if (!$officerPosition) {
                // Try partial match
                $officerPosition = OfficerPosition::where('name', 'ilike', "%{$singlePosition}%")->first();
                
                if (!$officerPosition) {
                    // Try fuzzy matching with existing positions
                    $allPositions = OfficerPosition::all();
                    $bestMatch = null;
                    $bestScore = PHP_INT_MAX;
                    
                    foreach ($allPositions as $pos) {
                        $distance = levenshtein(strtolower($singlePosition), strtolower($pos->name));
                        $maxDistance = ceil(max(strlen($singlePosition), strlen($pos->name)) * 0.3);
                        
                        if ($distance <= $maxDistance && $distance < $bestScore) {
                            $bestScore = $distance;
                            $bestMatch = $pos;
                        }
                    }
                    
                    if ($bestMatch) {
                        $officerPosition = $bestMatch;
                        Log::debug("Fuzzy matched officer position", [
                            'input' => $singlePosition,
                            'matched' => $bestMatch->name,
                            'distance' => $bestScore
                        ]);
                    } else {
                        // DO NOT create new position if not found
                        Log::debug("Skipping position - no match found in existing officer positions", [
                            'position' => $singlePosition,
                            'member_id' => $member->id,
                            'affiliate_id' => $affiliateId
                        ]);
                        continue; // Skip this position
                    }
                }
            }
            
            if ($officerPosition) {
                AffiliateOfficer::updateOrCreate(
                    [
                        'affiliate_id' => $affiliateId,
                        'position_id' => $officerPosition->id,
                        'member_id' => $member->id,
                    ],
                    [
                        'start_date' => now(),
                        'is_primary' => true,
                        'created_by' => Auth::id(),
                        'updated_by' => Auth::id(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
                $assignedPositions[] = $officerPosition->name;
            }
        }
        
        return $assignedPositions;
    }

    /**
     * Helper function to assign a role to user
     */
    private function assignRoleToUser($userId, $roleId): bool
    {
        try {
            UserRole::updateOrCreate(
                [
                    'user_id' => $userId,
                    'role_id' => $roleId,
                ],
                [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            
            Log::debug("Role assigned to user", [
                'user_id' => $userId,
                'role_id' => $roleId
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to assign role to user: " . $e->getMessage(), [
                'user_id' => $userId,
                'role_id' => $roleId
            ]);
            return false;
        }
    }
}