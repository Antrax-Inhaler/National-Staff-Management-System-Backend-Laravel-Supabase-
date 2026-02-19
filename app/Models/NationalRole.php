<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrganizationRole extends BaseModel
{
    use HasFactory;

    protected $table = 'national_roles';

    protected $fillable = [
        'name',
        'description'
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];


    /**
     * Get the members who have this national role.
     */
    public function members()
    {
        return $this->hasManyThrough(
            Member::class,
            MemberOrganizationRole::class,
            'role_id', // Foreign key on member_national_roles table...
            'id', // Foreign key on members table...
            'id', // Local key on national_roles table...
            'member_id' // Local key on member_national_roles table...
        );
    }

    /**
     * Get the member national role assignments.
     */
    public function memberAssignments()
    {
        return $this->hasMany(MemberOrganizationRole::class, 'role_id');
    }
    protected static function booted()
    {
        static::created(function ($model) {
            $model->updateUserRoles();
        });

        static::updated(function ($model) {
            $model->updateUserRoles();
        });

        static::deleted(function ($model) {
            $model->updateUserRoles();
        });
    }

public function updateUserRoles()
{
    $user = $this->member->user;
    $roles = $this->getUserRoles(); // Your existing role fetching logic
    
    // Update Supabase user metadata
    $supabase = new \Supabase\Client(
        env('SUPABASE_URL'),
        env('SUPABASE_KEY')
    );
    
    $supabase->auth->admin->updateUserById($user->supabase_uid, [
        'user_metadata' => [
            'roles' => $roles,
            'affiliate_id' => $this->member->affiliate_id
        ]
    ]);
}

 public function memberOrganizationRoles()
    {
        return $this->hasMany(MemberOrganizationRole::class, 'role_id');
    }
}
