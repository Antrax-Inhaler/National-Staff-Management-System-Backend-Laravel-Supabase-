<?php

namespace App\Services\CsvImport;

use App\Models\CsvImport;
use App\Models\CsvImportChunk;
use Illuminate\Support\Facades\Log;

class ImportSummaryService
{
    /**
     * Get import summary
     */
    public function getImportSummary(string $importId, int $userId): array
    {
        $import = CsvImport::where('id', $importId)
            ->where('user_id', $userId)
            ->firstOrFail();

        $chunks = CsvImportChunk::where('import_id', $importId)
            ->orderBy('chunk_index')
            ->get();

        return [
            'import' => $this->formatImportData($import),
            'chunks' => $this->formatChunksData($chunks),
            'statistics' => $this->calculateStatistics($import, $chunks),
            'timeline' => $this->buildTimeline($import, $chunks)
        ];
    }

    /**
     * Get user's imports
     */
    public function getUserImports(int $userId, int $limit = 20): array
    {
        $imports = CsvImport::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return $imports->map(function ($import) {
            return $this->formatImportData($import);
        })->toArray();
    }

    /**
     * Format import data
     */
    private function formatImportData(CsvImport $import): array
    {
        return [
            'id' => $import->id,
            'filename' => $import->filename,
            'status' => $import->status,
            'total_rows' => $import->total_rows,
            'processed_rows' => $import->processed_rows,
            'success_rows' => $import->success_rows,
            'failed_rows' => $import->failed_rows,
            'total_chunks' => $import->total_chunks,
            'processed_chunks' => $import->processed_chunks,
            'chunk_size' => $import->chunk_size,
            'progress_percentage' => $this->calculateProgress($import),
            'created_at' => $import->created_at,
            'started_at' => $import->started_at,
            'completed_at' => $import->completed_at,
            'duration' => $this->calculateDuration($import),
            'has_errors' => !empty($import->errors)
        ];
    }

    /**
     * Format chunks data
     */
    private function formatChunksData($chunks): array
    {
        return $chunks->map(function ($chunk) {
            return [
                'chunk_index' => $chunk->chunk_index,
                'status' => $chunk->status,
                'total_rows' => $chunk->total_rows,
                'processed_rows' => $chunk->processed_rows,
                'success_rows' => $chunk->success_rows,
                'failed_rows' => $chunk->failed_rows,
                'start_row' => $chunk->start_row,
                'end_row' => $chunk->end_row,
                'started_at' => $chunk->started_at,
                'completed_at' => $chunk->completed_at,
                'processing_duration' => $chunk->processing_duration,
                'has_errors' => !empty($chunk->errors)
            ];
        })->toArray();
    }

    /**
     * Calculate statistics
     */
    private function calculateStatistics(CsvImport $import, $chunks): array
    {
        $completedChunks = $chunks->where('status', 'completed')->count();
        $failedChunks = $chunks->where('status', 'failed')->count();
        $pendingChunks = $chunks->where('status', 'pending')->count();
        $processingChunks = $chunks->where('status', 'processing')->count();

        $totalProcessingTime = $chunks->sum('processing_duration');
        $avgChunkTime = $completedChunks > 0 ? $totalProcessingTime / $completedChunks : 0;

        return [
            'chunk_status_summary' => [
                'completed' => $completedChunks,
                'failed' => $failedChunks,
                'pending' => $pendingChunks,
                'processing' => $processingChunks
            ],
            'performance_metrics' => [
                'average_chunk_time_seconds' => round($avgChunkTime, 2),
                'total_processing_time_seconds' => $totalProcessingTime,
                'rows_per_second' => $import->processed_rows > 0 && $totalProcessingTime > 0 
                    ? round($import->processed_rows / $totalProcessingTime, 2) 
                    : 0
            ],
            'success_rates' => [
                'row_success_rate' => $import->processed_rows > 0 
                    ? round(($import->success_rows / $import->processed_rows) * 100, 2) 
                    : 0,
                'chunk_success_rate' => $import->total_chunks > 0 
                    ? round(($completedChunks / $import->total_chunks) * 100, 2) 
                    : 0
            ]
        ];
    }

    /**
     * Build timeline
     */
    private function buildTimeline(CsvImport $import, $chunks): array
    {
        $timeline = [
            [
                'event' => 'uploaded',
                'timestamp' => $import->created_at,
                'details' => "File {$import->filename} uploaded"
            ]
        ];

        if ($import->started_at) {
            $timeline[] = [
                'event' => 'started_processing',
                'timestamp' => $import->started_at,
                'details' => "Processing started with {$import->total_chunks} chunks"
            ];
        }

        foreach ($chunks->whereNotNull('started_at')->sortBy('started_at') as $chunk) {
            $timeline[] = [
                'event' => 'chunk_started',
                'timestamp' => $chunk->started_at,
                'details' => "Chunk {$chunk->chunk_index} started (rows {$chunk->start_row}-{$chunk->end_row})"
            ];
        }

        foreach ($chunks->whereNotNull('completed_at')->sortBy('completed_at') as $chunk) {
            $timeline[] = [
                'event' => 'chunk_completed',
                'timestamp' => $chunk->completed_at,
                'details' => "Chunk {$chunk->chunk_index} completed ({$chunk->success_rows}/{$chunk->total_rows} rows)"
            ];
        }

        if ($import->paused_at) {
            $timeline[] = [
                'event' => 'paused',
                'timestamp' => $import->paused_at,
                'details' => 'Import paused'
            ];
        }

        if ($import->resumed_at) {
            $timeline[] = [
                'event' => 'resumed',
                'timestamp' => $import->resumed_at,
                'details' => 'Import resumed'
            ];
        }

        if ($import->completed_at) {
            $timeline[] = [
                'event' => 'completed',
                'timestamp' => $import->completed_at,
                'details' => "Import completed - {$import->success_rows} successful rows"
            ];
        }

        if ($import->stopped_at) {
            $timeline[] = [
                'event' => 'stopped',
                'timestamp' => $import->stopped_at,
                'details' => 'Import stopped'
            ];
        }

        return array_reverse($timeline); // Show latest first
    }

    /**
     * Calculate progress percentage
     */
    private function calculateProgress(CsvImport $import): float
    {
        if ($import->total_rows === 0) {
            return 0;
        }

        return round(($import->processed_rows / $import->total_rows) * 100, 2);
    }

    /**
     * Calculate duration
     */
    private function calculateDuration(CsvImport $import): ?int
    {
        if (!$import->started_at) {
            return null;
        }

        $endTime = $import->completed_at ?? $import->stopped_at ?? now();
        
        return $endTime->diffInSeconds($import->started_at);
    }
}