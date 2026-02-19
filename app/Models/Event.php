<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends BaseModel
{
    use SoftDeletes;

    protected $table = 'events';
    
    protected $fillable = [
        'cvent_event_id',
        'title',
        'description',
        'event_type',
        'status',
        'start_date',
        'end_date',
        'time_zone',
        'venue_name',
        'address',
        'city',
        'state',
        'country',
        'is_virtual',
        'registration_url',
        'registration_limit',
        'registration_deadline',
        'attendance_mode',
        'is_waitlist_enabled',
        'affiliate_id',
        'affiliate_name',
        'is_national_event',
        'attendance_count',
        'attendance_report_url',
        'location',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'registration_deadline' => 'datetime',
        'is_virtual' => 'boolean',
        'is_waitlist_enabled' => 'boolean',
        'is_national_event' => 'boolean',
        'is_deleted' => 'boolean'
    ];

    // Relationships
    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function attendances()
    {
        return $this->hasMany(EventAttendance::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Scopes
    public function scopeUpcoming($query)
    {
        return $query->where('start_date', '>=', now())
                    ->where('status', '!=', 'Cancelled');
    }

    public function scopePast($query)
    {
        return $query->where('start_date', '<', now());
    }

    public function scopeOrganization($query)
    {
        return $query->where('is_national_event', true);
    }

    public function scopeAffiliateEvents($query, $affiliateId)
    {
        return $query->where('affiliate_id', $affiliateId);
    }

    public function scopeSearch($query, $searchTerm)
    {
        return $query->where(function($q) use ($searchTerm) {
            $q->where('title', 'ilike', "%{$searchTerm}%")
              ->orWhere('description', 'ilike', "%{$searchTerm}%")
              ->orWhere('venue_name', 'ilike', "%{$searchTerm}%");
        });
    }
}