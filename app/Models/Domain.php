<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Domain extends BaseModel
{
    protected $fillable = [
        'domain',
        'affiliate_id',
        'is_blacklisted',
        'type'
    ];

    protected $table = 'domains';

    public function scopeSearch($query, $term)
    {
        if ($term) {
            $query->where('domain', 'like', "%{$term}%");
        }

        return $query;
    }

    public function scopeBlacklisted($query)
    {
        return $query->where('is_blacklisted', true);
    }

    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class);
    }
}
