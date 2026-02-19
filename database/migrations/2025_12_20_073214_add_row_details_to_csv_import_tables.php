<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add columns to csv_import_chunks table
        Schema::table('csv_import_chunks', function (Blueprint $table) {
            // Add JSON column for detailed row results
            $table->json('row_results')->nullable()->after('errors');
            
            // Add columns for tracking different action types
            $table->integer('created_rows')->default(0)->after('failed_rows');
            $table->integer('updated_rows')->default(0)->after('created_rows');
            $table->integer('skipped_rows')->default(0)->after('updated_rows');
            
            // Add indexes for better query performance
            $table->index(['import_id', 'status'], 'import_status_idx');
            $table->index(['import_id', 'chunk_index'], 'import_chunk_idx');
            $table->index(['import_id', 'completed_at'], 'import_completed_idx');
            
            // If storage_disk doesn't exist, add it
            if (!Schema::hasColumn('csv_import_chunks', 'storage_disk')) {
                $table->string('storage_disk', 50)->nullable()->after('end_row');
            }
        });
        
        // Add columns to csv_imports table
        Schema::table('csv_imports', function (Blueprint $table) {
            // Add columns for aggregated statistics
            $table->integer('created_rows')->default(0)->after('failed_rows');
            $table->integer('updated_rows')->default(0)->after('created_rows');
            $table->integer('skipped_rows')->default(0)->after('updated_rows');
            
            // Add index for better query performance
            $table->index(['user_id', 'status'], 'user_status_idx');
            $table->index(['user_id', 'created_at'], 'user_created_idx');
            $table->index(['status', 'completed_at'], 'status_completed_idx');
        });
        
        // Update existing records to have default values
        $this->updateExistingRecords();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove columns from csv_import_chunks table
        Schema::table('csv_import_chunks', function (Blueprint $table) {
            $table->dropColumn(['row_results', 'created_rows', 'updated_rows', 'skipped_rows']);
            
            // Drop indexes
            $table->dropIndex('import_status_idx');
            $table->dropIndex('import_chunk_idx');
            $table->dropIndex('import_completed_idx');
            
            // Do not drop storage_disk as it might be needed by existing code
        });
        
        // Remove columns from csv_imports table
        Schema::table('csv_imports', function (Blueprint $table) {
            $table->dropColumn(['created_rows', 'updated_rows', 'skipped_rows']);
            
            // Drop indexes
            $table->dropIndex('user_status_idx');
            $table->dropIndex('user_created_idx');
            $table->dropIndex('status_completed_idx');
        });
    }
    
    /**
     * Update existing records to populate new columns with default values
     */
    private function updateExistingRecords(): void
    {
        // Only run if we have the table
        if (Schema::hasTable('csv_import_chunks')) {
            // Update existing chunks to have default values
            DB::table('csv_import_chunks')
                ->whereNull('row_results')
                ->update([
                    'row_results' => '[]',
                    'created_rows' => 0,
                    'updated_rows' => 0,
                    'skipped_rows' => 0,
                ]);
        }
        
        // Update csv_imports table
        if (Schema::hasTable('csv_imports')) {
            // For existing imports, we need to calculate the values from chunks
            $imports = DB::table('csv_imports')->get();
            
            foreach ($imports as $import) {
                $chunkStats = DB::table('csv_import_chunks')
                    ->where('import_id', $import->id)
                    ->selectRaw('SUM(created_rows) as total_created, SUM(updated_rows) as total_updated, SUM(skipped_rows) as total_skipped')
                    ->first();
                
                DB::table('csv_imports')
                    ->where('id', $import->id)
                    ->update([
                        'created_rows' => $chunkStats->total_created ?? 0,
                        'updated_rows' => $chunkStats->total_updated ?? 0,
                        'skipped_rows' => $chunkStats->total_skipped ?? 0,
                    ]);
            }
        }
    }
};