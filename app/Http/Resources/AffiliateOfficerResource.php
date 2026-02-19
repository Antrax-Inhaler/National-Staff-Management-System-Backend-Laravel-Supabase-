<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AffiliateOfficerResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'position' => $this->position->name ?? 'Unknown Position',
            'position_id' => $this->position_id,
            'member_name' => $this->member ? trim($this->member->first_name . ' ' . $this->member->last_name) : null,
            'member_id' => $this->member?->member_id,
            'first_name' => $this->member?->first_name,
            'last_name' => $this->member?->last_name,
            'mobile_phone' => $this->member?->mobile_phone,
            'work_phone' => $this->member?->work_phone,
            'work_email' => $this->member?->work_email,
            'home_email' => $this->member?->home_email ?? ($this->member?->user?->email ?? null),
            'profile_photo_url' => $this->member?->profile_photo_url,
            'affiliate_id' => $this->affiliate_id,
            'affiliate_name' => $this->affiliate?->name,
            'is_primary' => $this->is_primary,
            'is_vacant' => $this->is_vacant,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}