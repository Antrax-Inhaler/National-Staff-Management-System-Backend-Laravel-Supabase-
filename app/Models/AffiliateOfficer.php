<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\UpdatesUserRoles;

class AffiliateOfficer extends BaseModel
{
    use HasFactory, UpdatesUserRoles;

    protected $table = 'affiliate_officers';

    protected $fillable = [
        'affiliate_id',
        'position_id',
        'member_id',
        'start_date',
        'end_date',
        'is_primary',
        'is_vacant',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_vacant' => 'boolean',
    ];

    protected $hidden =
    [
        'created_at',
        'updated_at',
        'deleted_at',
        'created_by',
        'updated_by'
    ];


    /**
     * Get the affiliate that the officer belongs to.
     */
    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class, 'affiliate_id');
    }

    /**
     * Get the officer position details.
     */
    public function position()
    {
        return $this->belongsTo(OfficerPosition::class, 'position_id');
    }

    /**
     * Get the member who holds this officer position.
     */
    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    /**
     * Get the user who created this officer assignment.
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this officer assignment.
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope a query to only include current officers (not vacant and not ended).
     */
    public function scopeCurrent($query)
    {
        return $query->where('is_vacant', 0)
            ->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>', now());
            });
    }

    protected static function booted()
    {
        static::created(function ($model) {
            $model->updateUserRoles($model->member_id);
        });

        static::updated(function ($model) {
            $model->updateUserRoles($model->member_id);
        });

        static::deleted(function ($model) {
            $model->updateUserRoles($model->member_id);
        });
    }

    public function histories()
    {
        return $this->morphMany(OfficerHistory::class, 'entity');
    }
}
