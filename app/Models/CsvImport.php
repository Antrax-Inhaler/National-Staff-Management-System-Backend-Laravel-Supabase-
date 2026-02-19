<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CsvImport extends BaseModel
{
    use SoftDeletes;

    protected $table = 'csv_imports';
    
    protected $keyType = 'string';
    public $incrementing = false;
    
    protected $casts = [
        'details' => 'array',
        'errors' => 'array',
        'control_flags' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'paused_at' => 'datetime',
        'resumed_at' => 'datetime',
        'stopped_at' => 'datetime',
    ];
    
    protected $fillable = [
        'id',
        'filename',
        'file_path',
        'total_rows',
        'processed_rows',       // Rows processed so far
        'success_rows',         // Rows successfully saved
        'failed_rows',         // Rows that failed
        'created_rows',        // New records created
        'updated_rows',        // Existing records updated
        'skipped_rows',    
        'processed_rows',
        'success_rows',
        'failed_rows',
        'chunk_size',
        'total_chunks',
        'processed_chunks',
        'storage_disk', 
        'status', // pending, processing, paused, completed, failed, stopped
        'control_flags', // ['pause_requested', 'stop_requested', 'resume_requested']
        'user_id',
        'details',
        'errors',
        'started_at',
        'completed_at',
        'paused_at',
        'resumed_at',
        'stopped_at',
        'current_chunk_index', // Track current processing chunk
    ];
    
    // Default values
    protected $attributes = [
        'control_flags' => '[]',
        'details' => '[]',
        'errors' => '[]',
    ];
    
    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_PAUSED = 'paused';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_STOPPED = 'stopped';
    
    // Control flag constants
    const FLAG_PAUSE_REQUESTED = 'pause_requested';
    const FLAG_STOP_REQUESTED = 'stop_requested';
    const FLAG_RESUME_REQUESTED = 'resume_requested';
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function chunks()
    {
        return $this->hasMany(CsvImportChunk::class, 'import_id', 'id');
    }
    
    // Control methods
    public function requestPause()
    {
        $this->update([
            'control_flags' => array_merge($this->control_flags ?? [], [self::FLAG_PAUSE_REQUESTED]),
            'status' => self::STATUS_PAUSED,
            'paused_at' => now(),
        ]);
    }
    
    public function requestStop()
    {
        $this->update([
            'control_flags' => array_merge($this->control_flags ?? [], [self::FLAG_STOP_REQUESTED]),
            'status' => self::STATUS_STOPPED,
            'stopped_at' => now(),
        ]);
    }
    
    public function requestResume()
    {
        $this->update([
            'control_flags' => array_diff($this->control_flags ?? [], [
                self::FLAG_PAUSE_REQUESTED, 
                self::FLAG_RESUME_REQUESTED
            ]),
            'status' => self::STATUS_PROCESSING,
            'resumed_at' => now(),
        ]);
    }
    
    public function isPausable(): bool
    {
        return in_array($this->status, [self::STATUS_PROCESSING, self::STATUS_PENDING]);
    }
    
    public function isResumable(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }
    
    public function isStoppable(): bool
    {
        return in_array($this->status, [self::STATUS_PROCESSING, self::STATUS_PAUSED, self::STATUS_PENDING]);
    }
    
    public function shouldPause(): bool
    {
        return in_array(self::FLAG_PAUSE_REQUESTED, $this->control_flags ?? []);
    }
    
    public function shouldStop(): bool
    {
        return in_array(self::FLAG_STOP_REQUESTED, $this->control_flags ?? []);
    }
    
     public function getProgressPercentage(): float
    {
        if ($this->total_rows > 0) {
            return ($this->processed_rows / $this->total_rows) * 100;
        }
        
        if ($this->total_chunks > 0) {
            return ($this->processed_chunks / $this->total_chunks) * 100;
        }
        
        return 0;
    }
     public function getProgressData(): array
    {
        return [
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'success_rows' => $this->success_rows,
            'failed_rows' => $this->failed_rows,
            'created_rows' => $this->created_rows,
            'updated_rows' => $this->updated_rows,
            'skipped_rows' => $this->skipped_rows,
            'progress_percentage' => $this->getProgressPercentage(),
            'remaining_rows' => $this->total_rows - $this->processed_rows,
        ];
    }
    public function getEstimatedTimeRemaining(): ?string
    {
        if (!$this->started_at || !$this->processed_chunks || $this->completed_at) {
            return null;
        }
        
        $processedChunks = $this->processed_chunks;
        $totalChunks = $this->total_chunks;
        $elapsedSeconds = now()->diffInSeconds($this->started_at);
        
        if ($processedChunks > 0) {
            $secondsPerChunk = $elapsedSeconds / $processedChunks;
            $remainingChunks = $totalChunks - $processedChunks;
            $remainingSeconds = $secondsPerChunk * $remainingChunks;
            
            return $this->formatSeconds($remainingSeconds);
        }
        
        return null;
    }
    
    private function formatSeconds(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;
        
        if ($hours > 0) {
            return sprintf('%dh %dm %ds', $hours, $minutes, $seconds);
        }
        
        if ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $seconds);
        }
        
        return sprintf('%ds', $seconds);
    }
}