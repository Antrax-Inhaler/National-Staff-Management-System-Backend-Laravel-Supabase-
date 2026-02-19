<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class DocumentFolder extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $table = 'document_folders';

    protected $fillable = [
        'folder_name',
        'name',
        'parent_id',
        'affiliate_id',
        'root',
        'public_uid',
        'category_group'
    ];

    protected $casts = [
        'parent_id' => 'integer',
        'created_by' => 'integer',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    protected $appends = ['display_name'];

    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->folder_name
            ?? $this->affiliate?->name
            ?? 'Unnamed Folder';
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->public_uid)) {
                $model->public_uid = (string) Str::uuid();
            }
        });
    }

    /**
     * 📂 A folder has many documents
     */
    public function documents()
    {
        return $this->hasMany(Document::class, 'folder_id');
    }

    /**
     * 📂 A folder can have a parent folder
     */
    public function parent()
    {
        return $this->belongsTo(DocumentFolder::class, 'parent_id');
    }

    /**
     * 📂 A folder can have many child folders
     */
    public function children()
    {
        return $this->hasMany(DocumentFolder::class, 'parent_id');
    }

    /**
     * 📂 Recursive children (to build nested tree)
     */
    public function childrenRecursive()
    {
        return $this->children()->with('childrenRecursive');
    }

    public function getPath(): array
    {
        $path = [];
        $current = $this;

        while ($current) {
            $path[] = [
                'id' => $current->public_uid,
                'display_name' => $current->display_name,
                'parent_id' => $current->parent_id
            ];
            $current = $current->parent;
        }

        return array_reverse($path);
    }

    /**
     * Get the root folder of this folder.
     */
    public function getRootFolder(): DocumentFolder
    {
        $current = $this;

        while ($current->parent) {
            $current = $current->parent;
        }

        return $current;
    }
}
