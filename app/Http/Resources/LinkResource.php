<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LinkResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url,
            'description' => $this->description,
            'category' => $this->category,
            'display_order' => $this->display_order,
            'is_active' => $this->is_active,
            'is_public' => $this->is_public,
            'affiliate_id' => $this->affiliate_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}