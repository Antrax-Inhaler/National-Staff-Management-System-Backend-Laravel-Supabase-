<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class HelpVideoResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'public_uid' => $this->public_uid,
            'title' => $this->title,
            'description' => $this->description,
            'video_url' => $this->video_url,
            'thumbnail_url' => $this->thumbnail_url,
            'duration' => $this->duration,
            'category' => $this->category,
            'view_count' => $this->view_count,
            'is_active' => $this->is_active,
            'created_by' => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
                'email' => $this->creator->email
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}