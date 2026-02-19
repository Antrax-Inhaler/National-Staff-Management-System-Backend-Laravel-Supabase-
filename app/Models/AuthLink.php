<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuthLink extends BaseModel
{
    use HasFactory;
    protected $table = 'auth_links';
    protected $fillable = [
        'user_id',
        'token',
        'expires_at',
        'used'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isValid()
    {
        return !$this->used && $this->expires_at->isFuture();
    }

    public function markAsUsed()
    {
        $this->update(['used' => true]);
    }
}