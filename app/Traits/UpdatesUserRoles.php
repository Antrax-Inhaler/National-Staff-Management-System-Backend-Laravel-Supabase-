<?php

namespace App\Traits;

use App\Services\SupabaseService;

trait UpdatesUserRoles
{
    protected function updateUserRoles($memberId)
    {
        $member = \App\Models\Member::with(['user', 'nationalRoles.nationalRole', 'officerRoles.position'])
            ->find($memberId);

        if (!$member || !$member->user || !$member->user->supabase_uid) {
            return false;
        }

        $roles = [];
        $affiliate_id = $member->affiliate_id;

        // Get national roles
        foreach ($member->nationalRoles as $nationalRole) {
            $roles[] = $nationalRole->nationalRole->name;
        }

        // Get affiliate officer roles
        foreach ($member->officerRoles as $officerRole) {
            if (!$officerRole->is_vacant && 
                (!$officerRole->end_date || $officerRole->end_date > now())) {
                $roles[] = $officerRole->position->name;
            }
        }

        $supabaseService = new SupabaseService();
        return $supabaseService->updateUserMetadata($member->user->supabase_uid, [
            'roles' => $roles,
            'affiliate_id' => $affiliate_id
        ]);
    }
}