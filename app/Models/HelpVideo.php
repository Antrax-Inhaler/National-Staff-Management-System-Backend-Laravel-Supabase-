<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;

class HelpVideo extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_uid',
        'title',
        'description',
        'video_url',
        'thumbnail_url',
        'duration',
        'category',
        'view_count',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'view_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $hidden = [
        'created_by',
        'updated_at',
    ];

    protected $appends = [
        'duration_formatted',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->public_uid)) {
                $model->public_uid = Str::uuid();
            }
            
            if (empty($model->created_by) && auth()->check()) {
                $model->created_by = auth()->id();
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeCategory($query, $category)
    {
        if ($category && $category !== 'all') {
            return $query->where('category', $category);
        }
        return $query;
    }

    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%");
            });
        }
        return $query;
    }

    protected function durationFormatted(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (!$this->duration) {
                    return '--:--';
                }

                if (strpos($this->duration, ':') !== false) {
                    return $this->duration;
                }

                $totalSeconds = intval($this->duration);
                $minutes = floor($totalSeconds / 60);
                $seconds = $totalSeconds % 60;
                
                return sprintf('%d:%02d', $minutes, $seconds);
            }
        );
    }
}