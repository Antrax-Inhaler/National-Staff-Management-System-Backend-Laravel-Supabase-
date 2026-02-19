<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;

class UserRole extends BasePivot
{
    protected $table = 'user_roles';
    protected $fillable = [
        'user_id',
        'role_id',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];
    // In UserRole model
public function user()
{
    return $this->belongsTo(User::class);
}

public function role()
{
    return $this->belongsTo(Role::class);
}

// In User model
public function member()
{
    return $this->hasOne(Member::class, 'user_id');
}

public function roles()
{
    return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id');
}

// In Role model
public function users()
{
    return $this->belongsToMany(User::class, 'user_roles', 'role_id', 'user_id');
}
}
