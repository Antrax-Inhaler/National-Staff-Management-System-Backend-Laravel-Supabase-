<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OfficerPosition extends BaseModel
{
    use HasFactory;

    protected $table = 'officer_positions';

    protected $fillable = [
        'name',
        'display_order'
    ];

    /**
     * Get the affiliate officers who hold this position.
     */
    public function affiliateOfficers()
    {
        return $this->hasMany(AffiliateOfficer::class, 'position_id');
    }

    /**
     * Get the current officers who hold this position.
     */
    public function currentOfficers()
    {
        return $this->hasMany(AffiliateOfficer::class, 'position_id')
            ->where('is_vacant', 0)
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>', now());
            });
    }

    // OfficerPosition.php
    public function currentOfficer()
    {
        return $this->hasOne(AffiliateOfficer::class, 'position_id')
            ->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>', now());
            });
    }

    public function primaryOfficer()
    {
        return $this->hasOne(AffiliateOfficer::class, 'position_id')
            ->where('is_primary', true)
            ->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>', now());
            });
    }

    public function secondaryOfficer()
    {
        return $this->hasOne(AffiliateOfficer::class, 'position_id')
            ->where('is_primary', false)
            ->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>', now());
            });
    }

    /**
     * Scope to get positions with their current and previous officers.
     */
    public function scopeWithCurrentAndPreviousOfficers($query)
    {
        return $query->with([
            'currentOfficer' => function ($query) {
                $query->where('is_vacant', 0)
                    ->with('member:id,first_name,last_name') // Add member relationship
                    ->orderBy('start_date', 'desc');
            },
            'previousOfficer' => function ($query) {
                $query->where('is_vacant', 0)
                    ->where('end_date', '<=', now())
                    ->with('member:id,first_name,last_name') // Add member relationship
                    ->orderBy('end_date', 'desc')
                    ->limit(1);
            }
        ]);
    }

    /**
     * Get the previous officer who held this position.
     */
    public function previousOfficer()
    {
        return $this->hasOne(AffiliateOfficer::class, 'position_id')
            ->where('is_vacant', 0)
            ->where('end_date', '<=', now())
            ->orderBy('end_date', 'desc');
    }

    /**
     * Get the previous officers who held this position.
     */
    public function previousOfficers()
    {
        return $this->hasMany(AffiliateOfficer::class, 'position_id')
            ->where('is_vacant', 0)
            ->where('end_date', '<=', now())
            ->orderBy('end_date', 'desc');
    }
}
