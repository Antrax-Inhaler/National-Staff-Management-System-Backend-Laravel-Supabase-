<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Link extends BaseModel
{
    use HasFactory;
    protected $table = 'links';

    protected $fillable = [
        'title',
        'url',
        'description',
        'category',
        'display_order',
        'is_active',
        'created_by',
        'updated_by',
        'affiliate_id',
        'is_public'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_public' => 'boolean',
    ];

    public function scopeSearch($query, $term)
    {
        $search = "%{$term}%";
        return $query->where(function ($q) use ($search) {
            $q->where('title', 'ILIKE', $search)
                ->orWhere('url', 'ILIKE', $search)
                ->orWhere('description', 'ILIKE', $search)
                ->orWhere('category', 'ILIKE', $search);
        });
    }

    public function scopeCategory($query, $term)
    {;
        return $query->where(function ($q) use ($term) {
            $q->where('category', $term);
        });
    }

    public function scopeIsActive($query, $active)
    {
        // Convert to array for consistency
        $values = (array) $active;

        // Map each value to its corresponding boolean
        $booleans = array_map(function ($value) {
            return match (strtolower($value)) {
                'active' => true,
                'inactive' => false,
                default => null, // skip invalid values
            };
        }, $values);

        // Filter out nulls in case of invalid strings
        $booleans = array_filter($booleans, fn($v) => $v !== null);

        // If no valid values provided, just return query unmodified
        if (empty($booleans)) {
            return $query;
        }

        return $query->whereIn('is_active', $booleans);
    }


    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class);
    }
}
