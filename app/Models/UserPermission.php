<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;

class UserPermission extends BasePivot
{
    protected $table = 'user_permissions';


    protected $fillable = [
        'user_id',
        'permission_id',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];
}
