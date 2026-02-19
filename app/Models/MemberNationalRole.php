<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\UpdatesUserRoles;

class MemberOrganizationRole extends BaseModel
{
    use HasFactory, UpdatesUserRoles;

    protected $table = 'member_national_roles';

    protected $fillable = [
        'member_id',
        'role_id',
        'created_by'
    ];

    /**
     * Get the member that owns the national role.
     */
    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    /**
     * Get the national role details.
     */
    public function nationalRole()
    {
        return $this->belongsTo(OrganizationRole::class, 'role_id');
    }
 public function role()
    {
        return $this->belongsTo(OrganizationRole::class, 'role_id');
    }

    /**
     * Get the user who created this role assignment.
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
     protected static function booted()
    {
        static::created(function ($model) {
            $model->updateUserRoles($model->member->user_id);
        });

        static::updated(function ($model) {
            $model->updateUserRoles($model->member->user_id);
        });

        static::deleted(function ($model) {
            $model->updateUserRoles($model->member->user_id);
        });
    }
}