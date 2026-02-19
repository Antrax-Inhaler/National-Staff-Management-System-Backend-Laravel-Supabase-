<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliateOfficer;
use App\Models\Member;
use App\Models\Role;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AffiliateDashboardController extends Controller
{
    public function directory(Request $request)
    {
        try {
            $currentUserId = Auth::id();
            
            $currentMember = Member::where('user_id', $currentUserId)->first();
            
            if (!$currentMember) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with any member record'
                ], 404);
            }
            
            $currentAffiliateId = $currentMember->affiliate_id;
            $affiliate = Affiliate::find($currentAffiliateId);
            
            $affiliateOfficers = $this->getAffiliateOfficers($currentAffiliateId);
            $nationalOfficers = $this->getAllOrganizationOfficers();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'affiliate_officers' => $affiliateOfficers,
                    'national_officers' => $nationalOfficers,
                    'affiliate_name' => $affiliate->name ?? 'Unknown Affiliate',
                    'total_officers' => count($affiliateOfficers),
                    'total_national_officers' => count($nationalOfficers)
                ]
            ]);
            
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load dashboard data',
                'error' => $th->getMessage()
            ], 500);
        }
    }
    
    private function getAffiliateOfficers($affiliateId)
    {
        $currentOfficers = AffiliateOfficer::where('affiliate_id', $affiliateId)
            ->where('is_vacant', false)
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>', now());
            })
            ->with([
                'position:id,name',
                'member:id,first_name,last_name,member_id,profile_photo_url,user_id,mobile_phone,work_phone,work_email,home_email',
                'member.user:id,email'
            ])
            ->get();
        
        $officersData = [];
        
        foreach ($currentOfficers as $officer) {
            if (!$officer->member) continue;
            
            $member = $officer->member;
            
            $mobilePhone = $member->mobile_phone ?? null;
            $workPhone = $member->work_phone ?? null;
            $workEmail = $member->work_email ?? null;
            $homeEmail = $member->home_email ?? ($member->user->email ?? null);
            
            // For affiliate officers, don't generate official email
            // They only get official email if they have national leadership roles
            $officialEmail = null; // Affiliate officers don't get official email
            
            $officersData[] = [
                'position' => $officer->position->name ?? 'Unknown Position',
                'member_name' => trim($member->first_name . ' ' . $member->last_name),
                'mobile_phone' => $mobilePhone,
                'work_phone' => $workPhone,
                'work_email' => $workEmail, // Use real work email for affiliate officers
                'real_email' => $workEmail,
                'home_email' => $homeEmail,
                'profile_photo_url' => $member->profile_photo_url,
                'member_id' => $member->member_id,
                'affiliate_officer_id' => $officer->id,
                'is_primary' => $officer->is_primary,
                'is_national_officer' => false
            ];
        }
        
        return $officersData;
    }
    
    private function getAllOrganizationOfficers()
    {
        $excludeRoles = ['affiliate_member', 'affiliate_officer', 'affiliate_administrator'];
        
        $nationalRoles = Role::whereNotIn('name', $excludeRoles)->get();
        
        $nationalRoleIds = $nationalRoles->pluck('id')->toArray();
        
        if (empty($nationalRoleIds)) {
            return [];
        }
        
        $nationalOfficers = UserRole::whereIn('role_id', $nationalRoleIds)
            ->with([
                'user:id,email',
                'user.member:id,user_id,first_name,last_name,member_id,profile_photo_url,mobile_phone,work_phone,work_email,home_email',
                'role:id,name,description'
            ])
            ->get()
            ->map(function ($userRole) {
                $user = $userRole->user;
                $member = $user->member ?? null;
                $role = $userRole->role;
                
                if (!$member || !$role) return null;
                
                $mobilePhone = $member->mobile_phone ?? null;
                $workPhone = $member->work_phone ?? null;
                $workEmail = $member->work_email ?? null;
                $homeEmail = $member->home_email ?? ($user->email ?? null);
                
                // Check if the role is a national leadership role
                $isOrganizationLeadershipRole = $this->isOrganizationLeadershipRole($role->name);
                
                // Only generate official email for national leadership roles
                $officialEmail = $isOrganizationLeadershipRole 
                    ? $this->generateNameBasedEmail($member->first_name, $member->last_name)
                    : null;
                
                $formattedPosition = $this->formatOrganizationPositionName($role->name);
                
                return [
                    'position' => $formattedPosition,
                    'role_name_raw' => $role->name,
                    'member_name' => trim($member->first_name . ' ' . $member->last_name),
                    'mobile_phone' => $mobilePhone,
                    'work_phone' => $workPhone,
                    'work_email' => $officialEmail ?? $workEmail, // Use official email if available, otherwise real email
                    'real_email' => $workEmail,
                    'home_email' => $homeEmail,
                    'profile_photo_url' => $member->profile_photo_url,
                    'member_id' => $member->member_id,
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'user_role_id' => $userRole->id,
                    'is_national_officer' => true,
                    'has_official_email' => $isOrganizationLeadershipRole
                ];
            })
            ->filter()
            ->values()
            ->toArray();
        
        usort($nationalOfficers, function($a, $b) {
            return strcmp($a['position'], $b['position']);
        });
        
        return $nationalOfficers;
    }
    
    private function generateNameBasedEmail($firstName, $lastName)
    {
        if (!$firstName || !$lastName) {
            return 'member@organizationstaff.org';
        }
        
        $cleanFirstName = strtolower(preg_replace('/[^a-zA-Z]/', '', $firstName));
        $cleanLastName = strtolower(preg_replace('/[^a-zA-Z]/', '', $lastName));
        
        if (!$cleanFirstName || !$cleanLastName) {
            return 'member@organizationstaff.org';
        }
        
        return $cleanFirstName . '.' . $cleanLastName . '@organizationstaff.org';
    }
    
    private function formatOrganizationPositionName($roleName)
    {
        $positionMappings = [
            'national_administrator' => 'Organization Administrator',
            'national_admin' => 'Organization Administrator',
            '_executive_commitee' => ' Executive Committee',
            '_executive_committee' => ' Executive Committee',
            '_research_commitee' => ' Research Committee',
            '_research_committee' => ' Research Committee',
            'president' => 'President',
            'vice_president_defense' => 'Vice President Defense',
            'vice_president_program' => 'Vice President Program',
            'secretary' => 'Secretary',
            'treasurer' => 'Treasurer',
            'region_1_director' => 'Region 1 Director',
            'region_2_director' => 'Region 2 Director',
            'region_3_director' => 'Region 3 Director',
            'region_4_director' => 'Region 4 Director',
            'region_5_director' => 'Region 5 Director',
            'region_6_director' => 'Region 6 Director',
            'region_7_director' => 'Region 7 Director',
            'at_large_director_associate' => 'At-Large Director Associate',
            'at_large_director_professional' => 'At-Large Director Professional',
            'committee_chair' => 'Committee Chair',
            'board_member' => 'Board Member',
            'advisor' => 'Advisor',
            'national_officer' => 'Organization Officer'
        ];
        
        if (isset($positionMappings[$roleName])) {
            return $positionMappings[$roleName];
        }
        
        $formatted = str_replace('_', ' ', $roleName);
        
        if (stripos($formatted, ' ') === 0) {
            $formatted = ' ' . ucwords(substr($formatted, 4));
        } else {
            $formatted = ucwords($formatted);
        }
        
        return $formatted;
    }
    
    /**
     * Check if a role is a national leadership role
     *
     * @param string $roleName
     * @return bool
     */
    private function isOrganizationLeadershipRole($roleName)
    {
        $roleName = strtolower($roleName);
        
        // Organization leadership roles
        $nationalLeadershipRoles = [
            'president',
            'vice president defense',
            'vice_president_defense',
            'vice president program',
            'vice_president_program',
            'secretary',
            'treasurer',
        ];
        
        // Check for Region X Directors (Region 1-7)
        for ($i = 1; $i <= 7; $i++) {
            $nationalLeadershipRoles[] = "region {$i} director";
            $nationalLeadershipRoles[] = "region_{$i}_director";
        }
        
        // Check for At Large X Directors
        $nationalLeadershipRoles[] = "at large director associate";
        $nationalLeadershipRoles[] = "at_large_director_associate";
        $nationalLeadershipRoles[] = "at large director professional";
        $nationalLeadershipRoles[] = "at_large_director_professional";
        
        // Check for exact matches
        if (in_array($roleName, $nationalLeadershipRoles)) {
            return true;
        }
        
        // Check for partial matches (e.g., roles containing "director")
        foreach (['director'] as $keyword) {
            if (strpos($roleName, $keyword) !== false) {
                // But exclude non-leadership director roles
                $excludedRoles = ['committee_chair', 'board_member', 'advisor', 'national_administrator'];
                foreach ($excludedRoles as $excluded) {
                    if (strpos($roleName, $excluded) !== false) {
                        return false;
                    }
                }
                return true;
            }
        }
        
        return false;
    }
}