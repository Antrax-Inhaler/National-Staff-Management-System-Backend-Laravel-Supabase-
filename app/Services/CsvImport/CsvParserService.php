<?php

namespace App\Services\CsvImport;

use League\Csv\Reader;
use League\Csv\CharsetConverter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Csv\Statement;

class CsvParserService
{
    /**
     * Parse entire CSV file
     */
    public function parseFile(string $filePath): array
    {
        $content = Storage::get($filePath);
        
        $csv = Reader::createFromString($content);
        $this->detectAndConvertEncoding($csv);
        
        $csv->setHeaderOffset(0);
        $headers = $csv->getHeader();
        $records = iterator_to_array($csv->getRecords());
        
        // Normalize record keys
        return $this->normalizeRecords($records, $headers);
    }
     public function parseFileFromStorage(string $filePath, string $storageDisk = 'supabase'): array
    {
        $content = Storage::disk($storageDisk)->get($filePath);
        
        $csv = Reader::createFromString($content);
        $this->detectAndConvertEncoding($csv);
        
        $csv->setHeaderOffset(0);
        $headers = $csv->getHeader();
        $records = iterator_to_array($csv->getRecords());
        
        // Normalize record keys
        return $this->normalizeRecords($records, $headers);
    }
        public function parseChunkFromStorage(string $filePath, int $startRow, int $endRow, string $storageDisk = 'supabase'): array
    {
        $content = Storage::disk($storageDisk)->get($filePath);
        
        $csv = Reader::createFromString($content);
        $this->detectAndConvertEncoding($csv);
        
        $csv->setHeaderOffset(0);
        $headers = $csv->getHeader();
        
        // Set iterator to start at specific row
        $records = [];
        $iterator = $csv->getRecords();
        $currentRow = 0;
        
        foreach ($iterator as $record) {
            $currentRow++;
            if ($currentRow >= $startRow && $currentRow <= $endRow) {
                $records[] = $record;
            }
            if ($currentRow > $endRow) {
                break;
            }
        }
        
        return $this->normalizeRecords($records, $headers);
    }

    /**
     * Get total rows in CSV from Supabase storage (excluding header)
     */
    public function getTotalRowsFromStorage(string $filePath, string $storageDisk = 'supabase'): int
    {
        $content = Storage::disk($storageDisk)->get($filePath);
        
        $csv = Reader::createFromString($content);
        $this->detectAndConvertEncoding($csv);
        
        $csv->setHeaderOffset(0);
        
        return count($csv) - 1; // Exclude header
    }

    /**
     * Parse a chunk of CSV file
     */
    public function parseChunk(string $filePath, int $startRow, int $endRow): array
    {
        $content = Storage::get($filePath);
        
        $csv = Reader::createFromString($content);
        $this->detectAndConvertEncoding($csv);
        
        $csv->setHeaderOffset(0);
        $headers = $csv->getHeader();
        
        // Set iterator to start at specific row
        $records = [];
        $iterator = $csv->getRecords();
        $currentRow = 0;
        
        foreach ($iterator as $record) {
            $currentRow++;
            if ($currentRow >= $startRow && $currentRow <= $endRow) {
                $records[] = $record;
            }
            if ($currentRow > $endRow) {
                break;
            }
        }
        
        return $this->normalizeRecords($records, $headers);
    }

    /**
     * Get total rows in CSV (excluding header)
     */
    public function getTotalRows(string $filePath): int
    {
        $content = Storage::get($filePath);
        
        $csv = Reader::createFromString($content);
        $this->detectAndConvertEncoding($csv);
        
        $csv->setHeaderOffset(0);
        
        return count($csv) - 1; // Exclude header
    }

    /**
     * Detect and convert CSV encoding
     */
    private function detectAndConvertEncoding(Reader $csv): void
    {
        $inputBom = $csv->getInputBOM();
        
        if ($inputBom === Reader::BOM_UTF16_LE || $inputBom === Reader::BOM_UTF16_BE) {
            CharsetConverter::addTo($csv, 'UTF-16', 'UTF-8');
            Log::info('Converted UTF-16 to UTF-8');
        }
    }

    /**
     * Normalize record keys to handle encoding issues
     */
   private function normalizeRecords(array $records, array $headers): array
{
    $normalized = [];
    
    // DEBUG: Log original headers
    Log::debug("Original CSV headers:", $headers);
    
    // Clean headers first
    $cleanedHeaders = [];
    foreach ($headers as $header) {
        $cleanedHeaders[] = $this->cleanString($header);
    }
    
    Log::debug("Cleaned headers:", $cleanedHeaders);
    
    foreach ($records as $record) {
        $normalizedRecord = [];
        
        foreach ($cleanedHeaders as $cleanHeader) {
            $value = null;
            
            // Try multiple approaches to find the value
            if (isset($record[$cleanHeader])) {
                $value = $record[$cleanHeader];
            } else {
                // Try case-insensitive matching
                foreach ($record as $key => $val) {
                    if (strcasecmp($this->cleanString($key), $cleanHeader) === 0) {
                        $value = $val;
                        break;
                    }
                }
            }
            
            $normalizedRecord[$cleanHeader] = $value ?? null;
        }
        
        // DEBUG: Log a sample record
        if (empty($normalized)) {
            Log::debug("Sample normalized record:", $normalizedRecord);
        }
        
        $normalized[] = $normalizedRecord;
    }
    
    return $normalized;
}

    /**
     * Clean string helper
     */
    private function cleanString(string $string): string
    {
        $string = str_replace("\xEF\xBB\xBF", '', $string);
        $string = str_replace("\xFF\xFE", '', $string);
        $string = str_replace("\xFE\xFF", '', $string);
        $string = str_replace("\xFF\xFE\x00\x00", '', $string);
        $string = str_replace("\x00\x00\xFE\xFF", '', $string);
        
        return trim($string);
    }
public function readChunkFromStorage(
    string $filePath, 
    int $startRow, 
    int $endRow, 
    string $storageDisk = 'supabase'
): array {
    try {
        $fileContent = Storage::disk($storageDisk)->get($filePath);
        
        if (!$fileContent) {
            throw new \Exception("File not found: {$filePath}");
        }
        
        // Use simple string parsing instead of League CSV
        $lines = explode("\n", trim($fileContent));
        
        if (empty($lines)) {
            return [];
        }
        
        // Get header (first line)
        $header = str_getcsv($lines[0]);
        
        // Process data rows
        $chunkRecords = [];
        $dataRowCount = 0;
        
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) {
                continue; // Skip empty lines
            }
            
            // Check if this row is in our chunk
            if ($dataRowCount >= $startRow && $dataRowCount <= $endRow) {
                $row = str_getcsv($line);
                
                // Ensure row has same columns as header
                if (count($row) !== count($header)) {
                    // Pad or truncate
                    if (count($row) < count($header)) {
                        $row = array_pad($row, count($header), '');
                    } else {
                        $row = array_slice($row, 0, count($header));
                    }
                }
                
                $chunkRecords[] = array_combine($header, $row);
            }
            
            $dataRowCount++;
            
            // Stop if we've processed enough rows
            if ($dataRowCount > $endRow) {
                break;
            }
        }
        
        Log::info('Chunk extracted', [
            'expected_rows' => $endRow - $startRow + 1,
            'actual_rows' => count($chunkRecords),
            'total_data_rows' => $dataRowCount
        ]);
        
        return $chunkRecords;
        
    } catch (\Exception $e) {
        Log::error('Failed to read chunk: ' . $e->getMessage());
        throw $e;
    }
}
}