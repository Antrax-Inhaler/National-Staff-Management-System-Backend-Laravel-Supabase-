<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Arbitration extends BaseModel
{
    use HasFactory;
    protected $table = 'arbitrations';
    protected $fillable = [
        'title',
        'description',
        'affiliate_id',
        'file_path',
        'file_name',
        'file_size',
        'uploaded_by'
    ];

    protected $casts = [
        'file_size' => 'integer',
        'uploaded_at' => 'datetime',
    ];

    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}