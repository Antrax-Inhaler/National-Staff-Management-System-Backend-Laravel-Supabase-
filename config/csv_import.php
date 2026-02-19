<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CSV Import Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration settings for CSV import functionality including
    | chunk processing, storage, and cleanup settings.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Chunk Processing Settings
    |--------------------------------------------------------------------------
    */
    'chunk_size' => env('IMPORT_CHUNK_SIZE', 1000),
    'max_rows_per_request' => env('IMPORT_MAX_ROWS_PER_REQUEST', 10000),
    
    /*
    |--------------------------------------------------------------------------
    | Queue Settings
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'name' => 'csv-import',
        'timeout' => 1800, // 30 minutes per chunk
        'tries' => 3,
        'backoff' => [60, 300, 600], // Retry after 1, 5, 10 minutes
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Storage Settings
    |--------------------------------------------------------------------------
    */
    'storage' => [
        'disk' => env('CSV_IMPORT_STORAGE_DISK', 'supabase'),
        'path' => 'csv-imports/' . date('Y/m/d'),
        'retention_days' => env('CSV_IMPORT_RETENTION_DAYS', 30),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Cleanup Settings
    |--------------------------------------------------------------------------
    */
    'cleanup' => [
        'keep_successful' => env('CSV_IMPORT_KEEP_SUCCESSFUL_FILES', false),
        'delete_after_days' => env('CSV_IMPORT_DELETE_AFTER_DAYS', 30),
        'cleanup_schedule' => env('CSV_IMPORT_CLEANUP_SCHEDULE', 'daily'),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Validation Settings
    |--------------------------------------------------------------------------
    */
    'validation' => [
        'max_file_size' => env('CSV_IMPORT_MAX_FILE_SIZE', 10485760), // 10MB
        'allowed_mimes' => [
            'text/csv',
            'text/plain',
            'application/vnd.ms-excel',
        ],
        'allowed_extensions' => ['csv', 'txt'],
        'required_headers' => [
            'Last Name',
            'First Name', 
            'Home Email',
            'Affiliate'
        ],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Monitoring Settings
    |--------------------------------------------------------------------------
    */
    'monitoring' => [
        'alert_threshold_minutes' => env('CSV_IMPORT_ALERT_THRESHOLD_MINUTES', 120),
        'max_concurrent_imports' => env('CSV_IMPORT_MAX_CONCURRENT', 3),
        'memory_threshold_mb' => env('CSV_IMPORT_MEMORY_THRESHOLD', 256),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Performance Settings
    |--------------------------------------------------------------------------
    */
    'performance' => [
        'batch_size' => env('CSV_IMPORT_BATCH_SIZE', 100),
        'use_transactions' => env('CSV_IMPORT_USE_TRANSACTIONS', true),
        'optimize_queries' => env('CSV_IMPORT_OPTIMIZE_QUERIES', true),
    ],
];