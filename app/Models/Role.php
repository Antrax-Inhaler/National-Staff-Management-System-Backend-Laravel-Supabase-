<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends BaseModel
{
    protected $table = 'roles';
    protected $fillable = [
        'name',
        'description',
        'parent_role_id', 
        'order'
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, env('DB_SCHEMA').'.role_permissions');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, env('DB_SCHEMA').'.user_roles');
    }

    public function parent()
    {
        return $this->belongsTo(Role::class, 'parent_role_id');
    }

    public function children()
    {
        return $this->hasMany(Role::class, 'parent_role_id');
    }

    public function histories()
    {
        return $this->morphMany(OfficerHistory::class, 'entity');
    }
}
