<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationInformationAttachment extends BaseModel
{
    use HasFactory;
    protected $table = 'national_information_attachments';
    protected $fillable = [
        'national_info_id',
        'file_name',
        'file_path',
        'file_size',
    ];
    public $timestamps = false;
    public function nationalInformation(): BelongsTo
    {
        return $this->belongsTo(OrganizationInformation::class, 'national_info_id');
    }

    public function getFileTypeAttribute(): string
    {
        $extension = pathinfo($this->file_path, PATHINFO_EXTENSION);
        
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            return 'image';
        } elseif (in_array($extension, ['mp4', 'mov', 'avi', 'wmv'])) {
            return 'video';
        } elseif (in_array($extension, ['pdf'])) {
            return 'pdf';
        } elseif (in_array($extension, ['doc', 'docx'])) {
            return 'word';
        } elseif (in_array($extension, ['xls', 'xlsx'])) {
            return 'excel';
        } else {
            return 'file';
        }
    }

    public function getIconAttribute(): string
    {
        switch ($this->file_type) {
            case 'image':
                return '📷';
            case 'video':
                return '🎬';
            case 'pdf':
                return '📄';
            case 'word':
                return '📝';
            case 'excel':
                return '📊';
            default:
                return '📎';
        }
    }
}