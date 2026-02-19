<?php

namespace App\Services\CsvImport;

use App\Models\CsvImport;
use App\Models\CsvImportChunk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportDataViewerService
{
    /**
     * Get detailed data for a specific import with pagination
     */
    public function getImportData(string $importId, int $userId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $import = CsvImport::where('id', $importId)
            ->where('user_id', $userId)
            ->firstOrFail();

        // Get all chunks for this import
        $chunks = CsvImportChunk::where('import_id', $importId)
            ->orderBy('chunk_index')
            ->get();

        // Collect all row results from all chunks
        $allRowResults = [];
        foreach ($chunks as $chunk) {
            $chunkResults = $chunk->row_results ?? [];
            foreach ($chunkResults as $rowResult) {
                $allRowResults[] = $this->formatRowResult($rowResult, $chunk, $importId);
            }
        }

        // Apply filters if provided
        if (!empty($filters)) {
            $allRowResults = $this->applyFilters($allRowResults, $filters);
        }

        // Sort by row number
        usort($allRowResults, function ($a, $b) {
            return ($a['row_index'] ?? 0) <=> ($b['row_index'] ?? 0);
        });

        // Calculate totals for summary
        $summary = $this->calculateSummary($allRowResults);
        $totalRows = count($allRowResults);
        $totalPages = ceil($totalRows / $perPage);
        $offset = ($page - 1) * $perPage;

        // Get paginated results
        $paginatedResults = array_slice($allRowResults, $offset, $perPage);

        return [
            'rows' => $paginatedResults,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_rows' => $totalRows,
                'per_page' => $perPage,
            ],
            'summary' => $summary,
            'filters' => $filters,
            'import_info' => [
                'id' => $import->id,
                'filename' => $import->filename,
                'status' => $import->status,
                'total_rows' => $import->total_rows,
                'success_rows' => $import->success_rows,
                'failed_rows' => $import->failed_rows,
            ]
        ];
    }

    /**
     * Format row result for frontend - FIXED: Handle missing record_id
     */
    private function formatRowResult(array $rowResult, CsvImportChunk $chunk, string $importId): array
    {
        // Safely get values with defaults
        $recordId = $rowResult['record_id'] ?? $rowResult['id'] ?? null;
        
        // Handle created_record safely
        $createdRecord = null;
        if ($recordId) {
            $createdRecord = [
                'id' => $recordId,
                'type' => 'member'
            ];
        } elseif (isset($rowResult['created_record'])) {
            $createdRecord = $rowResult['created_record'];
        } elseif (isset($rowResult['record'])) {
            $createdRecord = $rowResult['record'];
        }
        
        return [
            'id' => $recordId,
            'row_index' => $rowResult['row_number'] ?? $rowResult['row_index'] ?? 0,
            'data' => $rowResult['data'] ?? $rowResult['original_data'] ?? [],
            'action' => $rowResult['action'] ?? 'unknown',
            'status' => $rowResult['status'] ?? 'unknown',
            'errors' => $rowResult['errors'] ?? [],
            'message' => $rowResult['message'] ?? '',
            'original_data' => $rowResult['original_data'] ?? $rowResult['data'] ?? [],
            'created_record' => $createdRecord,
            'chunk_index' => $chunk->chunk_index,
            'import_id' => $importId,
            'processed_at' => $chunk->completed_at?->toDateTimeString(),
            'timestamp' => $rowResult['timestamp'] ?? $chunk->completed_at?->toDateTimeString(),
        ];
    }

    /**
     * Get data by action type with pagination
     */
    public function getDataByAction(string $importId, string $action, int $page = 1, int $perPage = 20): array
    {
        $import = CsvImport::findOrFail($importId);
        $chunks = CsvImportChunk::where('import_id', $importId)->get();

        // Collect all row results for this action
        $allResults = [];
        foreach ($chunks as $chunk) {
            $chunkResults = $chunk->getRowResultsByAction($action);
            foreach ($chunkResults as $rowResult) {
                $allResults[] = $this->formatRowResult($rowResult, $chunk, $importId);
            }
        }

        // Sort by row number
        usort($allResults, function ($a, $b) {
            return ($a['row_index'] ?? 0) <=> ($b['row_index'] ?? 0);
        });

        // Calculate totals
        $total = count($allResults);
        $totalPages = ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;
        $paginatedResults = array_slice($allResults, $offset, $perPage);

        // Calculate summary for this action
        $summary = $this->calculateSummary($allResults);

        return [
            'action' => $action,
            'rows' => $paginatedResults,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_rows' => $total,
                'per_page' => $perPage,
            ],
            'summary' => $summary,
            'import_info' => [
                'id' => $import->id,
                'filename' => $import->filename,
                'status' => $import->status,
            ]
        ];
    }

    /**
     * Search within import data
     */
    public function searchImportData(string $importId, string $searchTerm, array $filters = []): array
    {
        $import = CsvImport::findOrFail($importId);
        $chunks = CsvImportChunk::where('import_id', $importId)->get();

        $searchResults = [];
        $searchTerm = strtolower(trim($searchTerm));

        foreach ($chunks as $chunk) {
            $chunkResults = $chunk->row_results ?? [];
            foreach ($chunkResults as $rowResult) {
                $rowMatches = false;
                
                // Search in row data values
                foreach ($rowResult['data'] ?? [] as $value) {
                    if (is_string($value) && stripos(strtolower($value), $searchTerm) !== false) {
                        $rowMatches = true;
                        break;
                    }
                }
                
                // Search in errors
                foreach ($rowResult['errors'] ?? [] as $error) {
                    if (stripos(strtolower($error), $searchTerm) !== false) {
                        $rowMatches = true;
                        break;
                    }
                }
                
                // Search in message
                if (isset($rowResult['message']) && stripos(strtolower($rowResult['message']), $searchTerm) !== false) {
                    $rowMatches = true;
                }
                
                // Search in action/status
                if (stripos(strtolower($rowResult['action'] ?? ''), $searchTerm) !== false || 
                    stripos(strtolower($rowResult['status'] ?? ''), $searchTerm) !== false) {
                    $rowMatches = true;
                }
                
                if ($rowMatches) {
                    // Apply additional filters
                    if (!empty($filters)) {
                        $shouldInclude = true;
                        foreach ($filters as $key => $value) {
                            if ($key === 'action' && ($rowResult['action'] ?? '') !== $value) {
                                $shouldInclude = false;
                                break;
                            }
                            if ($key === 'chunk_index' && $chunk->chunk_index != $value) {
                                $shouldInclude = false;
                                break;
                            }
                        }
                        
                        if (!$shouldInclude) {
                            continue;
                        }
                    }
                    
                    $searchResults[] = $this->formatRowResult($rowResult, $chunk, $importId);
                }
            }
        }

        return [
            'search_term' => $searchTerm,
            'results' => $searchResults,
            'total' => count($searchResults),
            'import_info' => [
                'id' => $import->id,
                'filename' => $import->filename,
            ]
        ];
    }

    /**
     * Export import data to CSV
     */
    public function exportImportData(string $importId, string $action = null, int $userId): string
    {
        $import = CsvImport::where('id', $importId)
            ->where('user_id', $userId)
            ->firstOrFail();

        $chunks = CsvImportChunk::where('import_id', $importId)->get();
        
        // Generate CSV content
        $csvContent = $this->generateCsvContent($chunks, $action, $importId);
        
        // Save to temporary file
        $filename = "import_data_{$importId}_" . ($action ?: 'all') . '_' . now()->format('Ymd_His') . '.csv';
        $filePath = storage_path('app/temp/' . $filename);
        
        // Ensure directory exists
        if (!is_dir(dirname($filePath))) {
            mkdir(dirname($filePath), 0755, true);
        }
        
        file_put_contents($filePath, $csvContent);
        
        return $filePath;
    }

    /**
     * Generate CSV content from row results
     */
    private function generateCsvContent($chunks, ?string $action, string $importId): string
    {
        $headers = ['Row Number', 'Action', 'Status', 'Data', 'Errors', 'Message', 'Chunk Index', 'Timestamp'];
        $csvLines = [implode(',', $headers)];
        
        foreach ($chunks as $chunk) {
            $rowResults = $action 
                ? $chunk->getRowResultsByAction($action)
                : ($chunk->row_results ?? []);
            
            foreach ($rowResults as $rowResult) {
                $formattedRow = $this->formatRowResult($rowResult, $chunk, $importId);
                
                $csvLines[] = implode(',', [
                    $formattedRow['row_index'] ?? '',
                    $formattedRow['action'] ?? '',
                    $formattedRow['status'] ?? '',
                    $this->formatDataForCsv($formattedRow['data'] ?? []),
                    $this->formatErrorsForCsv($formattedRow['errors'] ?? []),
                    '"' . str_replace('"', '""', $formattedRow['message'] ?? '') . '"',
                    $formattedRow['chunk_index'] ?? '',
                    $formattedRow['processed_at'] ?? '',
                ]);
            }
        }
        
        return implode("\n", $csvLines);
    }

    /**
     * Format data for CSV (flatten JSON)
     */
    private function formatDataForCsv(array $data): string
    {
        $formatted = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $formatted[] = $key . ': ' . $value;
        }
        return '"' . str_replace('"', '""', implode('; ', $formatted)) . '"';
    }

    /**
     * Format errors for CSV
     */
    private function formatErrorsForCsv(array $errors): string
    {
        return '"' . str_replace('"', '""', implode('; ', $errors)) . '"';
    }

    /**
     * Apply filters to row results
     */
    private function applyFilters(array $rowResults, array $filters): array
    {
        return array_filter($rowResults, function ($row) use ($filters) {
            foreach ($filters as $key => $value) {
                if ($key === 'action' && $value !== 'all' && $row['action'] !== $value) {
                    return false;
                }
                if ($key === 'chunk_index' && $value && $row['chunk_index'] != $value) {
                    return false;
                }
                if ($key === 'status' && $value !== 'all' && $row['status'] !== $value) {
                    return false;
                }
                if ($key === 'has_errors' && $value && empty($row['errors'])) {
                    return false;
                }
            }
            return true;
        });
    }

    /**
     * Calculate summary statistics
     */
    private function calculateSummary(array $rows): array
    {
        $summary = [
            'total' => count($rows),
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'with_errors' => 0,
        ];
        
        foreach ($rows as $row) {
            $action = $row['action'] ?? 'unknown';
            if (isset($summary[$action])) {
                $summary[$action]++;
            }
            
            if (!empty($row['errors'])) {
                $summary['with_errors']++;
            }
        }
        
        return $summary;
    }

    /**
     * Get import statistics
     */
    public function getImportStatistics(string $importId, int $userId): array
    {
        $import = CsvImport::where('id', $importId)
            ->where('user_id', $userId)
            ->firstOrFail();

        $chunks = CsvImportChunk::where('import_id', $importId)->get();
        
        // Calculate detailed statistics
        $chunkStatusSummary = [
            'completed' => 0,
            'failed' => 0,
            'pending' => 0,
            'processing' => 0,
        ];
        
        $totalProcessingTime = 0;
        $completedChunks = 0;
        
        foreach ($chunks as $chunk) {
            $status = $chunk->status;
            if (isset($chunkStatusSummary[$status])) {
                $chunkStatusSummary[$status]++;
            }
            
            if ($chunk->processing_duration) {
                $totalProcessingTime += $chunk->processing_duration;
                $completedChunks++;
            }
        }
        
        $averageChunkTime = $completedChunks > 0 ? $totalProcessingTime / $completedChunks : 0;
        $rowsPerSecond = $import->processed_rows > 0 && $totalProcessingTime > 0 
            ? $import->processed_rows / $totalProcessingTime 
            : 0;
        
        $rowSuccessRate = $import->total_rows > 0 
            ? ($import->success_rows / $import->total_rows) * 100 
            : 0;
        
        $chunkSuccessRate = count($chunks) > 0 
            ? ($chunkStatusSummary['completed'] / count($chunks)) * 100 
            : 0;
        
        return [
            'import' => [
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
                'progress_percentage' => $import->getProgressPercentage(),
                'created_at' => $import->created_at,
                'started_at' => $import->started_at,
                'completed_at' => $import->completed_at,
                'duration' => $import->completed_at && $import->started_at 
                    ? $import->started_at->diffInSeconds($import->completed_at)
                    : null,
                'has_errors' => $import->failed_rows > 0,
            ],
            'statistics' => [
                'chunk_status_summary' => $chunkStatusSummary,
                'performance_metrics' => [
                    'average_chunk_time_seconds' => round($averageChunkTime, 2),
                    'total_processing_time_seconds' => round($totalProcessingTime, 2),
                    'rows_per_second' => round($rowsPerSecond, 2),
                ],
                'success_rates' => [
                    'row_success_rate' => round($rowSuccessRate, 2),
                    'chunk_success_rate' => round($chunkSuccessRate, 2),
                ],
            ],
            'actions_summary' => [
                'created' => $chunks->sum('created_rows'),
                'updated' => $chunks->sum('updated_rows'),
                'skipped' => $chunks->sum('skipped_rows'),
                'failed' => $chunks->sum('failed_rows'),
            ],
        ];
    }
}