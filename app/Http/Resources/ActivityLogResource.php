<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Convert to arrays
        $old = (array) $this->old_values;
        $new = (array) $this->new_values;

        // Remove created_at and updated_at
        $excludeKeys = ['created_at', 'updated_at'];
        $old = array_diff_key($old, array_flip($excludeKeys));
        $new = array_diff_key($new, array_flip($excludeKeys));

        return [
            'id' => $this->id,
            'action' => $this->action,
            'entity' => [
                'type' => class_basename($this->auditable_type),
                'id'   => $this->auditable_id,
                'name' => $this->auditable_name
                    ?? $this->auditable?->name
                    ?? '[Deleted record]'
            ],
            'performed_by' => [
                'id'   => $this->user_id,
                'name' => $this->user?->name,
            ],
            'old_values' => array_intersect_key($old, $new), // only keys that exist in new_values
            'new_values' => $new,
            'timestamp' => $this->created_at?->toISOString(),
        ];
    }
}
