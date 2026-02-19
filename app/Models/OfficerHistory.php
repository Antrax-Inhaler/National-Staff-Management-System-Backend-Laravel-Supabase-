<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OfficerHistory extends BaseModel
{
    use HasFactory;

        protected $table = 'officer_histories';
    protected $fillable = [
        'entity_id',
        'entity_type',
        'level',
        'user_id',
        'start_date',
        'end_date'
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
        'entity_type'
    ];

    public function entity()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
