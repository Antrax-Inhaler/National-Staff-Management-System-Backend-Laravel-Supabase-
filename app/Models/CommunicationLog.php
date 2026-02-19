<?php

namespace App\Models;

use App\Enums\CommunicationLogsStatusEnum;
use App\Enums\CommunicationTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommunicationLog extends BaseModel
{
    use HasFactory;

    protected $table = 'communication_logs';

    protected $fillable = [
        'subject',
        'message',
        'communication_type',
        'sent_to',
        'sent_by',
        'affiliate_id',
        'status'
    ];

    protected $casts = [
        'communication_type' => CommunicationTypeEnum::class,
        'status' => CommunicationLogsStatusEnum::class,
        'sent_at' => 'datetime',
    ];

    public function sentBy()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function scopeSms($query)
    {
        return $query->where('communication_type', 'SMS');
    }

    public function scopeEmail($query)
    {
        return $query->where('communication_type', 'Email');
    }

    public function markAsSent()
    {
        $this->update([
            'status' => 'Sent',
            'sent_at' => now()
        ]);
    }

    public function markAsFailed()
    {
        $this->update(['status' => 'Failed']);
    }
}