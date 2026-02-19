<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventAttendance extends BaseModel
{
    protected $table = 'event_attendances';
    
    protected $fillable = [
        'event_id',
        'member_id',
        'attendee_id',
        'first_name',
        'last_name',
        'email',
        'affiliate_id',
        'affiliate_name',
        'attendance_status',
        'check_in_time',
        'check_out_time',
        'attendance_date',
        'ticket_type',
        'registered_at',
        'attended_at'
    ];

    protected $casts = [
        'check_in_time' => 'datetime',
        'check_out_time' => 'datetime',
        'attendance_date' => 'date',
        'registered_at' => 'datetime',
        'attended_at' => 'datetime'
    ];

    // Relationships
    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class);
    }
}