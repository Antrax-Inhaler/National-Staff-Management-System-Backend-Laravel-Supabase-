<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OrganizationInformation extends BaseModel
{
    use HasFactory;
    protected $table = 'national_information';
    protected $fillable = [
        'public_uid', // Add this
        'type',
        'title',
        'content',
        'category',
        'author',
        'status',
        'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];
      public function setStatusAttribute($value)
    {
        // Force the status to be one of the allowed values
        $allowed = ['draft', 'published', 'archived'];
        $this->attributes['status'] = in_array($value, $allowed) ? $value : 'draft';
        
        // If status is being set to published and no publish date, set it
        if ($value === 'published' && empty($this->attributes['published_at'])) {
            $this->attributes['published_at'] = now();
        }
    }
    /**
     * The "booted" method of the model.
     */
    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->public_uid)) {
                $model->public_uid = (string) Str::uuid();
            }
        });

        static::created(function ($model) {
            // Ensure public_uid is set after creation (fallback)
            if (empty($model->public_uid)) {
                $model->update(['public_uid' => (string) Str::uuid()]);
            }
        });
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(OrganizationInformationAttachment::class, 'national_info_id');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published')
                    ->where('published_at', '<=', now());
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeArchived($query)
    {
        return $query->where('status', 'archived');
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Find by public_uid
     */
    public function scopeByPublicUid($query, $publicUid)
    {
        return $query->where('public_uid', $publicUid);
    }

    public function getFileAttachments()
    {
        return $this->attachments()->where(function($q) {
            $q->where('file_path', 'like', '%.pdf')
              ->orWhere('file_path', 'like', '%.doc%')
              ->orWhere('file_path', 'like', '%.xls%');
        })->get();
    }

    public function getImageAttachments()
    {
        return $this->attachments()->where(function($q) {
            $q->where('file_path', 'like', '%.jpg')
              ->orWhere('file_path', 'like', '%.jpeg')
              ->orWhere('file_path', 'like', '%.png')
              ->orWhere('file_path', 'like', '%.gif')
              ->orWhere('file_path', 'like', '%.webp');
        })->get();
    }

    public function getVideoAttachments()
    {
        return $this->attachments()->where(function($q) {
            $q->where('file_path', 'like', '%.mp4')
              ->orWhere('file_path', 'like', '%.mov')
              ->orWhere('file_path', 'like', '%.avi')
              ->orWhere('file_path', 'like', '%.wmv');
        })->get();
    }
}