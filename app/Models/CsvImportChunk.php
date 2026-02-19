<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CsvImportChunk extends BaseModel
{
    protected $table = 'csv_import_chunks';
    
     protected $casts = [
        'details' => 'array',
        'errors' => 'array',
        'row_results' => 'array', // NEW: Store detailed row results
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'processing_duration' => 'integer',
    ];
    
    protected $fillable = [
        'import_id',
        'chunk_index',
        'status',
        'total_rows',
        'processed_rows',
        'success_rows',
        'failed_rows',
        'created_rows',    // NEW: Count of created records
        'updated_rows',    // NEW: Count of updated records
        'skipped_rows',    // NEW: Count of skipped rows
        'start_row',
        'storage_disk',
        'end_row',
        'details',
        'errors',
        'row_results',     // NEW: Detailed results per row
        'started_at',
        'completed_at',
        'processing_duration',
    ];
    
    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_PAUSED = 'paused';
    const STATUS_STOPPED = 'stopped';
    
    public function import()
    {
        return $this->belongsTo(CsvImport::class, 'import_id', 'id');
    }
    
    public function markAsStarted()
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'started_at' => now(),
        ]);
    }
    
    // Updated method with duration parameter
public function markAsCompleted(array $details, array $rowResults, int $duration = null): void
    {
        // Calculate counts from row results
        $createdRows = count(array_filter($rowResults, fn($r) => $r['action'] === 'created'));
        $updatedRows = count(array_filter($rowResults, fn($r) => $r['action'] === 'updated'));
        $skippedRows = count(array_filter($rowResults, fn($r) => $r['action'] === 'skipped'));
        $failedRows = count(array_filter($rowResults, fn($r) => $r['action'] === 'failed'));
        
        $this->update([
            'status' => 'completed',
            'details' => $details,
            'row_results' => $rowResults,
            'created_rows' => $createdRows,
            'updated_rows' => $updatedRows,
            'skipped_rows' => $skippedRows,
            'failed_rows' => $failedRows,
            'success_rows' => $createdRows + $updatedRows,
            'completed_at' => now(),
            'processing_duration' => $duration ?? (int)$this->created_at->diffInSeconds(now(), true), // Ensure integer
            'updated_at' => now(),
        ]);
    }
    public function markAsFailed(array $details, string $error, int $duration = null): void
    {
        $this->update([
            'status' => 'failed',
            'details' => $details,
            'errors' => ['error' => $error],
            'completed_at' => now(),
            'processing_duration' => $duration ?? (int)$this->created_at->diffInSeconds(now(), true), // Ensure integer
            'updated_at' => now(),
        ]);
    }
    
    public function markAsPaused()
    {
        $this->update([
            'status' => self::STATUS_PAUSED,
            'completed_at' => now(),
        ]);
    }
    
    public function markAsStopped()
    {
        $this->update([
            'status' => self::STATUS_STOPPED,
            'completed_at' => now(),
        ]);
    }
     public function getRowResultsByAction(string $action = null): array
    {
        $results = $this->row_results ?? [];
        
        if ($action) {
            return array_filter($results, fn($row) => $row['action'] === $action);
        }
        
        return $results;
    }
    
    // Get sample rows for preview
    public function getSampleRows(int $limit = 10, string $action = null): array
    {
        $results = $this->getRowResultsByAction($action);
        return array_slice($results, 0, $limit);
    }
}