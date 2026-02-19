<?php

namespace App\Services\CsvImport;

use App\Models\CsvImport;
use App\Models\CsvImportChunk;
use App\Jobs\ProcessCsvImportChunk;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\Failed\FailedJobProviderInterface;

class ImportOrchestratorService
{
    private CsvParserService $csvParser;
    private DataCleanerService $dataCleaner;
    private ChunkProcessorService $chunkProcessor;
    private int $chunkSize;
    
    // Add a default storage disk constant
    private const DEFAULT_STORAGE_DISK = 'supabase';

    public function __construct(
        CsvParserService $csvParser,
        DataCleanerService $dataCleaner,
        ChunkProcessorService $chunkProcessor
    ) {
        $this->csvParser = $csvParser;
        $this->dataCleaner = $dataCleaner;
        $this->chunkProcessor = $chunkProcessor;
        $this->chunkSize = config('csv_import.chunk_size', 1000);
    }

    /**
     * Start a new import process
     */
public function startImport($file, int $userId, array $options = []): array
{
    DB::beginTransaction();

    try {
        $filename = $file->getClientOriginalName();
        
        // Store file - use Supabase storage
        $filePath = $this->storeFileInSupabase($file, $userId);
        
        // Parse CSV and analyze rows
        $csvAnalysis = $this->analyzeCsvFile($filePath, self::DEFAULT_STORAGE_DISK, $options);
        
        $totalRows = $csvAnalysis['total_data_rows'];
        
        // If no rows found, use raw count
        if ($totalRows === 0) {
            $totalRows = $csvAnalysis['raw_total_rows'] ?? 0;
            
            if ($totalRows === 0) {
                throw new \Exception('CSV file appears to be empty');
            }
        }
        
        $totalChunks = ceil($totalRows / $this->chunkSize);
        
        Log::info('CSV Analysis', [
            'filename' => $filename,
            'total_rows' => $totalRows,
            'chunks' => $totalChunks
        ]);
        
        // Create import record WITH ALL PROGRESS COLUMNS
        $import = CsvImport::create([
            'id' => Str::uuid(),
            'filename' => $filename,
            'file_path' => $filePath,
            'total_rows' => $totalRows,
            'processed_rows' => 0,                // Initialize
            'success_rows' => 0,                  // Initialize
            'failed_rows' => 0,                   // Initialize
            'created_rows' => 0,                  // Initialize
            'updated_rows' => 0,                  // Initialize
            'skipped_rows' => 0,                  // Initialize
            'chunk_size' => $this->chunkSize,
            'total_chunks' => $totalChunks,
            'processed_chunks' => 0,              // Initialize
            'status' => CsvImport::STATUS_PENDING,
            'user_id' => $userId,
            'storage_disk' => self::DEFAULT_STORAGE_DISK,
            'started_at' => now()
        ]);

        // Create chunk records
        $chunks = [];
        for ($i = 0; $i < $totalChunks; $i++) {
            $startRow = $i * $this->chunkSize;
            $endRow = min(($i + 1) * $this->chunkSize - 1, $totalRows - 1);
            $chunkTotalRows = $endRow - $startRow + 1;
            
            $chunk = CsvImportChunk::create([
                'import_id' => $import->id,
                'chunk_index' => $i,
                'status' => CsvImportChunk::STATUS_PENDING,
                'total_rows' => $chunkTotalRows,
                'processed_rows' => 0,           // Initialize
                'success_rows' => 0,             // Initialize
                'failed_rows' => 0,              // Initialize
                'created_rows' => 0,             // Initialize
                'updated_rows' => 0,             // Initialize
                'skipped_rows' => 0,             // Initialize
                'start_row' => $startRow,
                'end_row' => $endRow,
                'storage_disk' => self::DEFAULT_STORAGE_DISK,
            ]);
            
            $chunks[] = $chunk;
        }

        DB::commit();

        // Dispatch jobs
        $this->dispatchChunkJobs($import, $chunks);

        return [
            'import_id' => $import->id,
            'filename' => $filename,
            'total_rows' => $totalRows,
            'chunks' => $totalChunks,
            'chunk_size' => $this->chunkSize,
            'status' => $import->status,
            'storage_disk' => self::DEFAULT_STORAGE_DISK,
            'estimated_time' => $this->estimateProcessingTime($totalRows),
            'created_at' => $import->created_at,
            'progress' => [
                'total_rows' => $import->total_rows,
                'processed_rows' => $import->processed_rows,
                'progress_percentage' => 0,
                'processed_chunks' => $import->processed_chunks,
                'total_chunks' => $import->total_chunks,
            ]
        ];

    } catch (\Exception $e) {
        DB::rollBack();
        
        if (isset($filePath)) {
            Storage::disk(self::DEFAULT_STORAGE_DISK)->delete($filePath);
        }
        
        Log::error('Import failed: ' . $e->getMessage());
        throw $e;
    }
}
/**
 * Analyze CSV file to get accurate row counts and validation
 */
private function analyzeCsvFile(string $filePath, string $storageDisk, array $options = []): array
{
    try {
        $content = Storage::disk($storageDisk)->get($filePath);
        $lines = explode("\n", trim($content));
        
        if (empty($lines)) {
            throw new \Exception('CSV file is empty');
        }
        
        // Get header
        $headerLine = $lines[0];
        $header = str_getcsv($headerLine);
        $header = array_map('trim', $header);
        
        // Count actual data rows (non-empty lines after header)
        $dataRows = 0;
        $validRows = 0;
        
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (!empty($line)) {
                $dataRows++;
                $validRows++; // In relaxed mode, all non-empty rows are valid
            }
        }
        
        return [
            'total_rows_with_header' => count($lines),
            'total_data_rows' => $dataRows,
            'raw_total_rows' => $dataRows,
            'empty_rows' => count($lines) - 1 - $dataRows, // Total lines - header - data rows
            'invalid_rows' => 0,
            'valid_rows' => $validRows,
            'header' => $header,
            'sample_rows' => [],
            'validation_mode' => 'relaxed',
        ];
        
    } catch (\Exception $e) {
        Log::error('CSV analysis failed: ' . $e->getMessage());
        throw new \Exception('Failed to analyze CSV file');
    }
}
/**
 * RELAXED validation for CSV row - only basic checks
 */
private function validateCsvRowRelaxed(array $row, array $options = []): bool
{
    // Check if row has at least some non-empty values
    $hasData = false;
    foreach ($row as $value) {
        if (!empty(trim($value))) {
            $hasData = true;
            break;
        }
    }
    
    if (!$hasData) {
        return false;
    }
    
    // In RELAXED mode, we don't require specific fields
    // Just check that we have at least first name or last name
    $hasName = false;
    
    // Check common name field variations
    $nameFields = ['First Name', 'FirstName', 'first_name', 'First_Name', 'First', 'Last Name', 'LastName', 'last_name', 'Last_Name', 'Last'];
    
    foreach ($nameFields as $field) {
        if (isset($row[$field]) && !empty(trim($row[$field]))) {
            $hasName = true;
            break;
        }
    }
    
    // If no standard name field found, check all fields for name-like content
    if (!$hasName) {
        foreach ($row as $value) {
            if (strlen(trim($value)) > 1 && !is_numeric($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $hasName = true;
                break;
            }
        }
    }
    
    return $hasName;
}

/**
 * Sanitize row data for logging (remove sensitive info)
 */
private function sanitizeRowForLogging(array $row): array
{
    $sanitized = [];
    foreach ($row as $key => $value) {
        // Mask email addresses
        if (stripos($key, 'email') !== false && filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $parts = explode('@', $value);
            if (count($parts) === 2) {
                $sanitized[$key] = substr($parts[0], 0, 2) . '***@' . $parts[1];
            } else {
                $sanitized[$key] = '***@***';
            }
        } 
        // Mask phone numbers
        elseif (stripos($key, 'phone') !== false || stripos($key, 'Phone') !== false) {
            $sanitized[$key] = preg_replace('/\d/', '*', $value);
        }
        // Truncate long values
        elseif (strlen($value) > 50) {
            $sanitized[$key] = substr($value, 0, 50) . '...';
        }
        else {
            $sanitized[$key] = $value;
        }
    }
    return $sanitized;
}
private function validateCsvRow(array $row): bool
{
    // Check if row has at least some non-empty values
    $hasData = false;
    foreach ($row as $value) {
        if (!empty(trim($value))) {
            $hasData = true;
            break;
        }
    }
    
    if (!$hasData) {
        return false;
    }
    
    // Check for minimum required fields (adjust based on your needs)
    $requiredFields = ['Last Name', 'First Name', 'Email'];
    
    foreach ($requiredFields as $field) {
        if (!isset($row[$field]) || empty(trim($row[$field] ?? ''))) {
            Log::debug('Row missing required field:', [
                'field' => $field,
                'row_data' => $row,
            ]);
            return false;
        }
    }
    
    // Validate email format if present
    if (!empty($row['Email']) && !filter_var(trim($row['Email']), FILTER_VALIDATE_EMAIL)) {
        Log::debug('Invalid email in row:', [
            'email' => $row['Email'],
            'row_data' => $row,
        ]);
        return false;
    }
    
    return true;
}
    /**
     * Store uploaded file in Supabase storage
     */
 private function storeFileInSupabase($file, int $userId): string
    {
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $timestamp = now()->format('Ymd_His');
        $uniqueName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME))
            . '_' . $timestamp
            . '_' . Str::random(8)
            . '.' . $extension;
        
        // Organize by date and user
        $path = 'csv-imports/'
            . date('Y/m/d')
            . '/user_' . $userId
            . '/' . $uniqueName;
        
        // Store in Supabase
        Storage::disk(self::DEFAULT_STORAGE_DISK)
            ->put($path, file_get_contents($file->getRealPath()));
        
        return $path;
    }

    /**
     * Get public URL for Supabase file (optional)
     */
    private function getSupabaseFileUrl(string $filePath): ?string
    {
        try {
            // Get signed URL that expires in 24 hours
            $url = Storage::disk('supabase')->temporaryUrl(
                $filePath,
                now()->addHours(24)
            );
            
            return $url;
        } catch (\Exception $e) {
            Log::warning('Failed to generate Supabase URL', [
                'path' => $filePath,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Dispatch jobs for all chunks
     */
    private function dispatchChunkJobs(CsvImport $import, array $chunks): void
    {
        $import->update(['status' => CsvImport::STATUS_PROCESSING]);

        foreach ($chunks as $chunk) {
            // CRITICAL FIX: Pass all 8 parameters including storage_disk
            ProcessCsvImportChunk::dispatch(
                $import->id,                    // 1. importId
                $chunk->id,                     // 2. chunkId
                $import->file_path,             // 3. filePath
                $chunk->chunk_index,            // 4. chunkIndex
                $chunk->start_row,              // 5. startRow
                $chunk->end_row,                // 6. endRow
                $import->user_id,               // 7. userId
                $import->storage_disk           // 8. storageDisk - THIS WAS MISSING
            )->onQueue('csv-import');
        }

        Log::info("Dispatched {$import->total_chunks} chunk jobs for import {$import->id}", [
            'storage_disk' => $import->storage_disk
        ]);
    }

    /**
     * Pause import process
     */
    public function pauseImport(string $importId, int $userId): array
    {
        $import = $this->validateImportOwnership($importId, $userId);
        
        $import->update([
            'status' => CsvImport::STATUS_PAUSED,
            'paused_at' => now(),
            'control_flags' => array_merge($import->control_flags ?? [], ['paused' => true])
        ]);

        Log::info("Import {$importId} paused by user {$userId}", [
            'storage_disk' => $import->storage_disk
        ]);

        return [
            'import_id' => $importId,
            'status' => 'paused',
            'paused_at' => $import->paused_at,
            'storage_disk' => $import->storage_disk
        ];
    }

    /**
     * Resume import process
     */
    public function resumeImport(string $importId, int $userId): array
    {
        $import = $this->validateImportOwnership($importId, $userId);
        
        $import->update([
            'status' => CsvImport::STATUS_PROCESSING,
            'resumed_at' => now(),
            'control_flags' => array_merge($import->control_flags ?? [], ['paused' => false])
        ]);

        // Dispatch remaining chunks
        $pendingChunks = CsvImportChunk::where('import_id', $importId)
            ->where('status', CsvImportChunk::STATUS_PENDING)
            ->get();

        foreach ($pendingChunks as $chunk) {
            ProcessCsvImportChunk::dispatch(
                $import->id,
                $chunk->id,
                $import->file_path,
                $chunk->chunk_index,
                $chunk->start_row,
                $chunk->end_row,
                $import->user_id,
                $import->storage_disk // Pass storage disk to job
            )->onQueue('csv-import');
        }

        Log::info("Import {$importId} resumed by user {$userId}", [
            'storage_disk' => $import->storage_disk,
            'pending_chunks' => count($pendingChunks)
        ]);

        return [
            'import_id' => $importId,
            'status' => 'resumed',
            'resumed_at' => $import->resumed_at,
            'pending_chunks' => count($pendingChunks),
            'storage_disk' => $import->storage_disk
        ];
    }

    /**
     * Stop import process
     */
 public function stopImport(string $importId, int $userId): array
    {
        $import = $this->validateImportOwnership($importId, $userId);
        
        DB::beginTransaction();
        
        try {
            // 1. Update import status
            $import->update([
                'status' => CsvImport::STATUS_STOPPED,
                'stopped_at' => now(),
                'control_flags' => array_merge($import->control_flags ?? [], [
                    'stopped' => true,
                    'stop_requested_at' => now()->toDateTimeString()
                ])
            ]);
            
            // 2. Cancel pending chunks (not yet started)
            $pendingChunks = CsvImportChunk::where('import_id', $importId)
                ->where('status', CsvImportChunk::STATUS_PENDING)
                ->get();
            
            foreach ($pendingChunks as $chunk) {
                $chunk->update([
                    'status' => CsvImportChunk::STATUS_STOPPED,
                    'completed_at' => now(),
                    'details' => array_merge($chunk->details ?? [], [
                        'stopped_by_user' => $userId,
                        'stopped_at' => now()->toDateTimeString(),
                        'reason' => 'Import stopped by user'
                    ])
                ]);
            }
            
            // 3. Attempt to delete queued jobs from the queue
            $this->deleteQueuedJobs($importId);
            
            DB::commit();
            
            Log::info("Import {$importId} stopped by user {$userId}", [
                'storage_disk' => $import->storage_disk,
                'processed_rows' => $import->processed_rows,
                'success_rows' => $import->success_rows,
                'pending_chunks_stopped' => count($pendingChunks)
            ]);
            
            return [
                'import_id' => $importId,
                'status' => 'stopped',
                'stopped_at' => $import->stopped_at,
                'processed_rows' => $import->processed_rows,
                'success_rows' => $import->success_rows,
                'pending_chunks_stopped' => count($pendingChunks),
                'storage_disk' => $import->storage_disk
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to stop import {$importId}: " . $e->getMessage());
            throw $e;
        }
    }
    private function deleteQueuedJobs(string $importId): void
    {
        try {
            // This depends on your queue driver
            $queueDriver = config('queue.default');
            
            switch ($queueDriver) {
                case 'database':
                    $this->deleteDatabaseQueueJobs($importId);
                    break;
                case 'redis':
                    $this->deleteRedisQueueJobs($importId);
                    break;
                case 'sqs':
                    $this->deleteSqsQueueJobs($importId);
                    break;
                default:
                    Log::warning("Queue driver {$queueDriver} not supported for job deletion");
                    break;
            }
        } catch (\Exception $e) {
            Log::error("Failed to delete queued jobs: " . $e->getMessage());
            // Don't throw - we want to continue even if job deletion fails
        }
    }
    
    /**
     * Delete jobs from database queue
     */
    private function deleteDatabaseQueueJobs(string $importId): void
    {
        $queueTable = config('queue.connections.database.table', 'jobs');
        
        // Get jobs for this import
        $jobs = DB::table($queueTable)
            ->where('queue', 'csv-import')
            ->get();
        
        $deletedCount = 0;
        
        foreach ($jobs as $job) {
            $payload = json_decode($job->payload, true);
            
            // Check if this job belongs to our import
            if (isset($payload['data']['command']) && 
                str_contains($payload['data']['command'], 'ProcessCsvImportChunk')) {
                
                // Unserialize to check import ID
                try {
                    $unserialized = unserialize($payload['data']['command']);
                    if ($unserialized && 
                        property_exists($unserialized, 'importId') && 
                        $unserialized->importId === $importId) {
                        
                        // Delete the job
                        DB::table($queueTable)->where('id', $job->id)->delete();
                        $deletedCount++;
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed to unserialize job payload: " . $e->getMessage());
                }
            }
        }
        
        Log::info("Deleted {$deletedCount} queued jobs for import {$importId}");
    }
    
    /**
     * Delete jobs from Redis queue (simplified)
     */
    private function deleteRedisQueueJobs(string $importId): void
    {
        // Redis queue doesn't easily support selective deletion
        // You would need to use Redis commands to scan and remove
        Log::info("Redis queue - manual job deletion not implemented");
    }
    
    /**
     * Delete jobs from SQS queue (simplified)
     */
    private function deleteSqsQueueJobs(string $importId): void
    {
        // SQS doesn't support selective deletion by content
        Log::info("SQS queue - manual job deletion not implemented");
    }
    /**
     * Cleanup Supabase file (optional)
     */
    private function cleanupSupabaseFile(CsvImport $import): void
    {
        try {
            if ($import->file_path && $import->storage_disk === 'supabase') {
                Storage::disk('supabase')->delete($import->file_path);
                Log::info("Cleaned up Supabase file for import {$import->id}", [
                    'file_path' => $import->file_path
                ]);
            }
        } catch (\Exception $e) {
            Log::warning("Failed to cleanup Supabase file", [
                'import_id' => $import->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Validate import ownership
     */
    private function validateImportOwnership(string $importId, int $userId): CsvImport
    {
        $import = CsvImport::findOrFail($importId);
        
        if ($import->user_id !== $userId) {
            throw new \Exception('Unauthorized access to import');
        }
        
        return $import;
    }

    /**
     * Estimate processing time
     */
    private function estimateProcessingTime(int $totalRows): string
    {
        $rowsPerSecond = 50; // Conservative estimate for Supabase access
        $seconds = ceil($totalRows / $rowsPerSecond);
        
        if ($seconds < 60) {
            return "{$seconds} seconds";
        } elseif ($seconds < 3600) {
            $minutes = ceil($seconds / 60);
            return "{$minutes} minutes";
        } else {
            $hours = ceil($seconds / 3600);
            return "{$hours} hours";
        }
    }

    /**
     * Get import statistics
     */
    public function getImportStatistics(string $importId): array
    {
        $import = CsvImport::findOrFail($importId);
        
        // Get file info from Supabase
        $fileSize = null;
        $lastModified = null;
        
        try {
            if ($import->file_path && $import->storage_disk === 'supabase') {
                $fileSize = Storage::disk('supabase')->size($import->file_path);
                $lastModified = Storage::disk('supabase')->lastModified($import->file_path);
            }
        } catch (\Exception $e) {
            Log::warning("Failed to get Supabase file info", [
                'import_id' => $importId,
                'error' => $e->getMessage()
            ]);
        }
        
        return [
            'import' => $import->toArray(),
            'file_info' => [
                'size_bytes' => $fileSize,
                'size_human' => $fileSize ? $this->formatFileSize($fileSize) : null,
                'last_modified' => $lastModified ? date('Y-m-d H:i:s', $lastModified) : null,
                'storage_disk' => $import->storage_disk,
                'file_path' => $import->file_path,
                'file_url' => $this->getSupabaseFileUrl($import->file_path)
            ],
            'performance' => [
                'rows_per_second' => $import->duration > 0 
                    ? round($import->processed_rows / $import->duration, 2)
                    : 0,
                'chunks_per_hour' => $import->duration > 0 
                    ? round($import->processed_chunks / ($import->duration / 3600), 2)
                    : 0,
            ]
        ];
    }

    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}