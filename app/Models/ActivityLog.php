<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends BaseModel
{
    protected $table = 'activity_logs';
    protected $fillable = [
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'affiliate_id',
        'user_agent',
        'auditable_name'
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function auditable()
    {
        return $this->morphTo();
    }

    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class);
    }
}
