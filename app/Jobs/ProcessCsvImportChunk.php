<?php

namespace App\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Services\CsvImport\ChunkProcessorService;
use App\Services\CsvImport\CsvParserService;

class ProcessCsvImportChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800;
    public $tries = 3;
    public $backoff = [60, 300, 600];
    
    private $importId;
    private $chunkId;
    private $filePath;
    private $chunkIndex;
    private $startRow;
    private $endRow;
    private $userId;
    private $storageDisk;
    
    public function __construct(
        string $importId,
        string $chunkId,
        string $filePath,
        int $chunkIndex,
        int $startRow,
        int $endRow,
        int $userId,
        ?string $storageDisk = null
    ) {
        $this->importId = $importId;
        $this->chunkId = $chunkId;
        $this->filePath = $filePath;
        $this->chunkIndex = $chunkIndex;
        $this->startRow = $startRow;
        $this->endRow = $endRow;
        $this->userId = $userId;
        $this->storageDisk = $storageDisk ?? 'supabase';
    }
    
    public function handle(ChunkProcessorService $chunkProcessor, CsvParserService $csvParser)
    {
        $startTime = microtime(true);
        
        Log::info('=== CHUNK JOB STARTED ===', [
            'import_id' => $this->importId,
            'chunk_id' => $this->chunkId,
            'chunk_index' => $this->chunkIndex,
            'start_row' => $this->startRow,
            'end_row' => $this->endRow,
            'timestamp' => now()->toDateTimeString()
        ]);
        
        // 1. Check if import exists and get current state
        $import = \App\Models\CsvImport::find($this->importId);
        
        if (!$import) {
            Log::error("Import {$this->importId} not found, skipping chunk {$this->chunkIndex}");
            $this->delete();
            return;
        }
        
        // LOG INITIAL STATE
        Log::info("INITIAL IMPORT STATE before chunk processing", [
            'import_id' => $import->id,
            'total_rows' => $import->total_rows,
            'processed_rows' => $import->processed_rows,
            'processed_chunks' => $import->processed_chunks,
            'status' => $import->status
        ]);
        
        // 2. Check if import has been stopped before starting
        if ($import->status === \App\Models\CsvImport::STATUS_STOPPED) {
            Log::info("Import {$this->importId} is stopped, skipping chunk {$this->chunkIndex}");
            $this->markChunkAsStopped();
            $this->delete();
            return;
        }
        
        // 3. Get chunk record
        $chunk = \App\Models\CsvImportChunk::find($this->chunkId);
        if (!$chunk) {
            Log::error("Chunk {$this->chunkId} not found");
            $this->delete();
            return;
        }
        
        // 4. Check if chunk is already processed
        if (in_array($chunk->status, [
            \App\Models\CsvImportChunk::STATUS_COMPLETED,
            \App\Models\CsvImportChunk::STATUS_FAILED,
            \App\Models\CsvImportChunk::STATUS_STOPPED,
            \App\Models\CsvImportChunk::STATUS_PAUSED
        ])) {
            Log::info("Chunk {$this->chunkId} already in status: {$chunk->status}, skipping");
            $this->delete();
            return;
        }
        
        // 5. Mark chunk as started
        $chunk->markAsStarted();
        
        // Define stop check function
        $checkStopStatus = function() use ($import) {
            $freshImport = \App\Models\CsvImport::find($import->id);
            return $freshImport && $freshImport->status === \App\Models\CsvImport::STATUS_STOPPED;
        };
        
        try {
            // 6. Check stop status before reading data
            if ($checkStopStatus()) {
                throw new \Exception("Import was stopped before reading chunk data");
            }
            
            // 7. Read chunk data from the file
            $chunkData = $csvParser->readChunkFromStorage(
                $this->filePath,
                $this->startRow,
                $this->endRow,
                $this->storageDisk
            );
            
            $totalRowsInChunk = count($chunkData);
            Log::info("Chunk data read successfully", [
                'rows_count' => $totalRowsInChunk,
                'expected_rows' => ($this->endRow - $this->startRow + 1)
            ]);
            
            // 8. Check stop status before processing
            if ($checkStopStatus()) {
                throw new \Exception("Import was stopped after reading chunk data");
            }
            
            // 9. Process the chunk with ENHANCED LOGGING
            $result = $chunkProcessor->processChunk($this->chunkId, $chunkData);
            
            Log::info("Chunk processing COMPLETED", [
                'total_rows' => $result['total_rows'],
                'success_rows' => $result['success_rows'],
                'failed_rows' => $result['failed_rows'],
                'created_rows' => $result['created_rows'],
                'updated_rows' => $result['updated_rows'],
                'skipped_rows' => $result['skipped_rows'],
                'duration_seconds' => $result['duration_seconds'] ?? 0
            ]);
            
            // 10. Check stop status before updating statistics
            if ($checkStopStatus()) {
                throw new \Exception("Import was stopped after processing chunk");
            }
            
            // 11. Calculate processing duration - ROUND TO INTEGER
            $processingDuration = round(microtime(true) - $startTime);
            
            // 12. UPDATE IMPORT STATISTICS - FIXED TO UPDATE processed_rows
            $this->updateImportStatistics($import, $result, $processingDuration, $totalRowsInChunk);
            
            // 13. Update chunk with detailed results
            $chunk->update([
                'processed_rows' => $result['total_rows'],
                'success_rows' => $result['success_rows'],
                'failed_rows' => $result['failed_rows'],
                'created_rows' => $result['created_rows'],
                'updated_rows' => $result['updated_rows'],
                'skipped_rows' => $result['skipped_rows'],
                'row_results' => $result['row_results'] ?? [],
                'processing_duration' => $processingDuration,
            ]);
            
            // 14. Mark chunk as completed
            $chunk->markAsCompleted(
                [
                    'total_processed' => $result['total_rows'],
                    'success_count' => $result['success_rows'],
                    'error_count' => $result['failed_rows'],
                    'created_count' => $result['created_rows'],
                    'updated_count' => $result['updated_rows'],
                    'skipped_count' => $result['skipped_rows'],
                ],
                $result['row_results'] ?? [],
                $processingDuration
            );
            
            // 15. Check if all chunks are processed
            $this->checkIfImportCompleted($import);
            
            // LOG FINAL STATE
            $updatedImport = \App\Models\CsvImport::find($import->id);
            Log::info("FINAL IMPORT STATE after chunk processing", [
                'import_id' => $updatedImport->id,
                'processed_rows' => $updatedImport->processed_rows,
                'success_rows' => $updatedImport->success_rows,
                'failed_rows' => $updatedImport->failed_rows,
                'created_rows' => $updatedImport->created_rows,
                'updated_rows' => $updatedImport->updated_rows,
                'skipped_rows' => $updatedImport->skipped_rows,
                'processed_chunks' => $updatedImport->processed_chunks,
                'progress_percentage' => $updatedImport->total_rows > 0 
                    ? round(($updatedImport->processed_rows / $updatedImport->total_rows) * 100, 2)
                    : 0
            ]);
            
            Log::info("=== CHUNK JOB COMPLETED SUCCESSFULLY ===", [
                'chunk_index' => $this->chunkIndex,
                'rows_processed' => $result['total_rows'],
                'total_duration_seconds' => $processingDuration
            ]);
            
        } catch (\Exception $e) {
            Log::error("Failed to process chunk {$this->chunkIndex}: " . $e->getMessage(), [
                'import_id' => $this->importId,
                'chunk_id' => $this->chunkId,
                'trace' => $e->getTraceAsString()
            ]);
            
            $processingDuration = round(microtime(true) - $startTime);
            
            // Check if it was a stop request
            if (str_contains($e->getMessage(), 'Import was stopped')) {
                $this->markChunkAsStopped($chunk, $e->getMessage(), $processingDuration);
                return;
            } else {
                $this->markChunkAsFailed($chunk, $e->getMessage(), $processingDuration, $result ?? null);
                throw $e;
            }
        }
    }
    
    /**
     * Update import statistics in real-time - FIXED VERSION
     */
    private function updateImportStatistics($import, array $result, int $processingDuration, int $totalRowsInChunk): void
    {
        // Get fresh import data
        $freshImport = \App\Models\CsvImport::find($import->id);
        
        if (!$freshImport) {
            Log::error("Cannot update import statistics - import not found: {$import->id}");
            return;
        }
        
        // LOG BEFORE UPDATE
        Log::info("BEFORE DATABASE UPDATE - Import state:", [
            'import_id' => $freshImport->id,
            'current_processed_rows' => $freshImport->processed_rows,
            'current_success_rows' => $freshImport->success_rows,
            'chunk_total_rows' => $result['total_rows'],
            'chunk_success_rows' => $result['success_rows']
        ]);
        
        try {
            // CRITICAL FIX: Update processed_rows with the total rows from this chunk
            // This should INCREMENT the count, not just set it
            DB::table('csv_imports')
                ->where('id', $freshImport->id)
                ->update([
                    'processed_chunks' => DB::raw('processed_chunks + 1'),
                    'processed_rows' => DB::raw('processed_rows + ' . (int)$result['total_rows']), // FIXED: Add chunk total rows
                    'success_rows' => DB::raw('success_rows + ' . (int)$result['success_rows']),
                    'failed_rows' => DB::raw('failed_rows + ' . (int)$result['failed_rows']),
                    'created_rows' => DB::raw('created_rows + ' . (int)$result['created_rows']),
                    'updated_rows' => DB::raw('updated_rows + ' . (int)$result['updated_rows']),
                    'skipped_rows' => DB::raw('skipped_rows + ' . (int)$result['skipped_rows']),
                    'current_chunk_index' => $this->chunkIndex,
                    'updated_at' => now(),
                ]);
            
            // LOG AFTER UPDATE
            $updatedImport = \App\Models\CsvImport::find($freshImport->id);
            Log::info("AFTER DATABASE UPDATE - Import state:", [
                'import_id' => $updatedImport->id,
                'new_processed_rows' => $updatedImport->processed_rows,
                'new_success_rows' => $updatedImport->success_rows,
                'rows_added' => (int)$result['total_rows'],
                'expected_total' => $freshImport->processed_rows + (int)$result['total_rows']
            ]);
            
            // Verify the update
            if ($updatedImport->processed_rows != ($freshImport->processed_rows + (int)$result['total_rows'])) {
                Log::warning("PROCESSED ROWS UPDATE MISMATCH!", [
                    'expected' => $freshImport->processed_rows + (int)$result['total_rows'],
                    'actual' => $updatedImport->processed_rows,
                    'difference' => $updatedImport->processed_rows - ($freshImport->processed_rows + (int)$result['total_rows'])
                ]);
            }
            
            // Update cache for real-time progress
            $this->updateProgressCache($freshImport->id);
            
        } catch (\Exception $e) {
            Log::error("Failed to update import statistics: " . $e->getMessage(), [
                'import_id' => $freshImport->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    
    /**
     * Check if import is completed
     */
    private function checkIfImportCompleted($import): void
    {
        $updatedImport = \App\Models\CsvImport::find($import->id);
        
        if ($updatedImport->processed_chunks >= $updatedImport->total_chunks) {
            DB::table('csv_imports')
                ->where('id', $import->id)
                ->update([
                    'status' => \App\Models\CsvImport::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'details' => json_encode([
                        'total_processed' => $updatedImport->processed_rows,
                        'successful' => $updatedImport->success_rows,
                        'failed' => $updatedImport->failed_rows,
                        'created' => $updatedImport->created_rows,
                        'updated' => $updatedImport->updated_rows,
                        'skipped' => $updatedImport->skipped_rows,
                        'success_rate' => $updatedImport->processed_rows > 0 
                            ? round(($updatedImport->success_rows / $updatedImport->processed_rows) * 100, 2) 
                            : 0,
                        'total_time' => now()->diffInSeconds($updatedImport->started_at),
                        'chunks_processed' => $updatedImport->processed_chunks,
                        'completed_at' => now()->toDateTimeString()
                    ])
                ]);
            
            Log::info("🎉 IMPORT COMPLETED SUCCESSFULLY", [
                'import_id' => $import->id,
                'total_rows' => $updatedImport->total_rows,
                'processed_rows' => $updatedImport->processed_rows,
                'success_rows' => $updatedImport->success_rows,
                'failed_rows' => $updatedImport->failed_rows,
                'created_rows' => $updatedImport->created_rows,
                'updated_rows' => $updatedImport->updated_rows,
                'skipped_rows' => $updatedImport->skipped_rows,
                'success_rate' => $updatedImport->processed_rows > 0 
                    ? round(($updatedImport->success_rows / $updatedImport->processed_rows) * 100, 2) 
                    : 0,
                'total_duration_seconds' => now()->diffInSeconds($updatedImport->started_at)
            ]);
        }
    }
    
    /**
     * Update progress cache for real-time UI updates
     */
    private function updateProgressCache(string $importId): void
    {
        try {
            $import = \App\Models\CsvImport::find($importId);
            if ($import) {
                $cacheKey = "import_progress_{$importId}";
                Cache::put($cacheKey, [
                    'total_rows' => $import->total_rows,
                    'processed_rows' => $import->processed_rows,
                    'success_rows' => $import->success_rows,
                    'failed_rows' => $import->failed_rows,
                    'created_rows' => $import->created_rows,
                    'updated_rows' => $import->updated_rows,
                    'skipped_rows' => $import->skipped_rows,
                    'progress_percentage' => $import->total_rows > 0 
                        ? round(($import->processed_rows / $import->total_rows) * 100, 2)
                        : 0,
                    'timestamp' => now(),
                ], now()->addHours(6));
                
                Log::debug("Progress cache updated", [
                    'import_id' => $importId,
                    'processed_rows' => $import->processed_rows,
                    'cache_key' => $cacheKey
                ]);
            }
        } catch (\Exception $e) {
            Log::warning("Failed to update progress cache: " . $e->getMessage());
        }
    }
    
    /**
     * Mark chunk as stopped
     */
    private function markChunkAsStopped($chunk = null, string $reason = '', int $duration = 0): void
    {
        if (!$chunk) {
            $chunk = \App\Models\CsvImportChunk::find($this->chunkId);
        }
        
        if ($chunk) {
            $chunk->update([
                'status' => \App\Models\CsvImportChunk::STATUS_STOPPED,
                'completed_at' => now(),
                'details' => array_merge($chunk->details ?? [], [
                    'stopped_at' => now()->toDateTimeString(),
                    'reason' => $reason,
                    'stopped_by_user' => $this->userId,
                    'processing_duration' => $duration
                ]),
                'processing_duration' => $duration
            ]);
            
            Log::info("Chunk marked as stopped", [
                'chunk_id' => $chunk->id,
                'chunk_index' => $this->chunkIndex,
                'reason' => $reason
            ]);
        }
    }
    
    /**
     * Mark chunk as failed
     */
    private function markChunkAsFailed($chunk, string $error, int $duration, array $result = null): void
    {
        $chunk->markAsFailed(
            [
                'total_processed' => $result['total_rows'] ?? 0,
                'success_count' => $result['success_rows'] ?? 0,
                'error_count' => $result['failed_rows'] ?? 0,
                'created_count' => $result['created_rows'] ?? 0,
                'updated_count' => $result['updated_rows'] ?? 0,
                'skipped_count' => $result['skipped_rows'] ?? 0,
                'storage_disk' => $this->storageDisk,
            ],
            $error,
            $duration
        );
        
        Log::error("Chunk marked as failed", [
            'chunk_id' => $chunk->id,
            'chunk_index' => $this->chunkIndex,
            'error' => $error,
            'rows_processed' => $result['total_rows'] ?? 0
        ]);
    }
    
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessCsvImportChunk job failed', [
            'import_id' => $this->importId,
            'chunk_id' => $this->chunkId,
            'chunk_index' => $this->chunkIndex,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
        
        $chunk = \App\Models\CsvImportChunk::find($this->chunkId);
        if ($chunk) {
            $duration = $chunk->started_at ? $chunk->started_at->diffInSeconds(now(), true) : 0;
            
            $import = \App\Models\CsvImport::find($this->importId);
            if ($import && $import->status === \App\Models\CsvImport::STATUS_STOPPED) {
                $chunk->update([
                    'status' => \App\Models\CsvImportChunk::STATUS_STOPPED,
                    'completed_at' => now(),
                    'details' => array_merge($chunk->details ?? [], [
                        'stopped_at' => now()->toDateTimeString(),
                        'reason' => 'Import was stopped - job failed',
                        'stopped_by_user' => $this->userId,
                        'job_failure_error' => $exception->getMessage()
                    ]),
                    'processing_duration' => (int)$duration
                ]);
            } else {
                $chunk->markAsFailed(
                    [
                        'total_processed' => 0,
                        'success_count' => 0,
                        'error_count' => 1,
                        'created_count' => 0,
                        'updated_count' => 0,
                        'skipped_count' => 0,
                        'storage_disk' => $this->storageDisk,
                    ],
                    $exception->getMessage(),
                    (int)$duration
                );
            }
        }
    }
}