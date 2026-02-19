<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class MemberResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        $dateFields = ['date_of_birth', 'date_of_hire', 'created_at', 'updated_at'];

        foreach ($dateFields as $field) {
            if (isset($data[$field]) && $data[$field]) {
                if (is_string($data[$field])) {
                    continue;
                }

                try {
                    $data[$field] = $this->$field->format('Y-m-d');
                } catch (\Exception $e) {
                }
            }
        }

        $data['current_positions'] = $this->current_positions_or_null; // Collection | null
        
        // Check if member has national leadership role
        $hasOrganizationLeadershipRole = $this->hasOrganizationLeadershipRole();
        
        // Add official email only if member has national leadership role
        if ($hasOrganizationLeadershipRole) {
            $data['official_email'] = $this->generateOfficialEmail();
        } else {
            // For non-leadership members, set official_email to null
            $data['official_email'] = null;
        }

        if ($this->photo_path && $this->photo_signed_url) {
            $needsRefresh = !$this->photo_signed_expires_at ||
                Carbon::parse($this->photo_signed_expires_at)->lte(now());

            if ($needsRefresh) {
                $expiresAt = now()->addHours(5);
                $signedUrl = Storage::disk('supabase')
                    ->temporaryUrl($this->photo_path, $expiresAt);

                $data['photo_signed_url'] = $signedUrl;
            } else {
                $data['photo_signed_url'] = $this->photo_signed_url;
            }
        }

        return $data;
    }

    /**
     * Generate official email in format: firstname.lastname@organizationstaff.org
     *
     * @return string
     */
    private function generateOfficialEmail(): string
    {
        $firstName = $this->first_name ?? '';
        $lastName = $this->last_name ?? '';
        
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

    /**
     * Check if member has a national leadership role
     *
     * @return bool
     */
    private function hasOrganizationLeadershipRole(): bool
    {
        // Organization leadership roles based on your description:
        // President, Vice President Defense, Vice President Program, 
        // Secretary, Treasurer, Region X Directors, and At Large X Directors
        
        $nationalLeadershipRoles = [
            'president',
            'vice president defense',
            'vice president program',
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
        $nationalLeadershipRoles[] = "at large director professional";
        $nationalLeadershipRoles[] = "at_large_director_associate";
        $nationalLeadershipRoles[] = "at_large_director_professional";

        // Also check for any role containing "director" (case-insensitive)
        $nationalLeadershipRoles[] = "director";

        // Load user roles if not already loaded
        if (!$this->relationLoaded('user') || !$this->user || !$this->user->relationLoaded('roles')) {
            $this->load(['user.roles']);
        }

        // Check if user has any national leadership role
        if ($this->user && $this->user->roles) {
            foreach ($this->user->roles as $role) {
                $roleName = strtolower($role->name);
                
                // Check for exact matches
                if (in_array($roleName, $nationalLeadershipRoles)) {
                    return true;
                }
                
                // Check for partial matches (e.g., roles containing "director")
                foreach ($nationalLeadershipRoles as $leadershipRole) {
                    if (strpos($roleName, $leadershipRole) !== false) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}