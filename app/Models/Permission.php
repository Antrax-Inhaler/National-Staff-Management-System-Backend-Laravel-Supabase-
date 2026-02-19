<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends BaseModel
{
    protected $table = 'permissions';
    protected $fillable = [
        'name',
        'description',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];
}
