<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AffiliateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request)
    {

        $data = parent::toArray($request);

        $data['professional_count'] = $this->professional_count ?? 0;
        $data['associate_count'] = $this->associate_count ?? 0;
        $data['members_count'] = $this->members_count ?? 0;

        if ($this->logo_path && $this->logo_signed_url) {
            $needsRefresh = !$this->logo_signed_expires_at ||
                Carbon::parse($this->logo_signed_expires_at)->lte(now());

            if ($needsRefresh) {
                $expiresAt = now()->addHours(5);
                $signedUrl = Storage::disk('supabase')
                    ->temporaryUrl($this->logo_path, $expiresAt);

                $data['logo_signed_url'] = $signedUrl;
            } else {
                $data['logo_signed_url'] = $this->logo_signed_url;
            }
        }

        return $data;
    }
}
