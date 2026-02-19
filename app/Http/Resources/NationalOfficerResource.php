<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationOfficerResource extends JsonResource
{
    public function toArray($request)
    {
        $positionName = $this->role->name ?? 'Organization Officer';
        $formattedPosition = $this->formatPositionName($positionName);
        
        return [
            'id' => $this->id,
            'position' => $formattedPosition,
            'position_raw' => $positionName,
            'role_description' => $this->role?->description,
            'member_name' => $this->user?->member ? trim($this->user->member->first_name . ' ' . $this->user->member->last_name) : null,
            'member_id' => $this->user?->member?->member_id,
            'first_name' => $this->user?->member?->first_name,
            'last_name' => $this->user?->member?->last_name,
            'mobile_phone' => $this->user?->member?->mobile_phone,
            'work_phone' => $this->user?->member?->work_phone,
            'work_email' => $this->user?->member?->work_email,
            'home_email' => $this->user?->member?->home_email ?? ($this->user?->email ?? null),
            'profile_photo_url' => $this->user?->member?->profile_photo_url,
            'user_id' => $this->user_id,
            'role_id' => $this->role_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'member_status' => $this->user?->member?->status,
        ];
    }

    private function formatPositionName($roleName)
    {
        $positionMappings = [
            'national_administrator' => 'Organization Administrator',
            'national_admin' => 'Organization Administrator',
            'org_executive_commitee' => 'ORG Executive Committee',
            'org_executive_committee' => 'ORG Executive Committee',
            'org_research_commitee' => 'ORG Research Committee',
            'org_research_committee' => 'ORG Research Committee',
            'president' => 'President',
            'vice_president_defense' => 'Vice President Defense',
            'vice_president_program' => 'Vice President Program',
            'secretary' => 'Secretary',
            'region_1_director' => 'Region 1 Director',
            'region_2_director' => 'Region 2 Director',
            'region_3_director' => 'Region 3 Director',
            'region_4_director' => 'Region 4 Director',
            'region_5_director' => 'Region 5 Director',
            'region_6_director' => 'Region 6 Director',
            'region_7_director' => 'Region 7 Director',
            'at_large_director_associate' => 'At-Large Director Associate',
            'at_large_director_professional' => 'At-Large Director Professional',
            'national_officers' => 'Organization Officers'
        ];
        
        if (isset($positionMappings[$roleName])) {
            return $positionMappings[$roleName];
        }
        
        $formatted = str_replace('_', ' ', $roleName);
        
        if (stripos($formatted, 'nso ') === 0) {
            $formatted = 'ORG ' . ucwords(substr($formatted, 4));
        } else {
            $formatted = ucwords($formatted);
        }
        
        return $formatted;
    }
}