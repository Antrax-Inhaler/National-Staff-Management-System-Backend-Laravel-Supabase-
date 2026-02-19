<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('csv_imports', function (Blueprint $table) {
            // I-check muna kung hindi pa existing ang mga column na ito
            if (!Schema::hasColumn('csv_imports', 'total_rows')) {
                $table->integer('total_rows')->default(0)->after('file_path');
            }
            
            if (!Schema::hasColumn('csv_imports', 'processed_rows')) {
                $table->integer('processed_rows')->default(0)->after('total_rows');
            }
            
            if (!Schema::hasColumn('csv_imports', 'success_rows')) {
                $table->integer('success_rows')->default(0)->after('processed_rows');
            }
            
            if (!Schema::hasColumn('csv_imports', 'failed_rows')) {
                $table->integer('failed_rows')->default(0)->after('success_rows');
            }
            
            if (!Schema::hasColumn('csv_imports', 'created_rows')) {
                $table->integer('created_rows')->default(0)->after('failed_rows');
            }
            
            if (!Schema::hasColumn('csv_imports', 'updated_rows')) {
                $table->integer('updated_rows')->default(0)->after('created_rows');
            }
            
            if (!Schema::hasColumn('csv_imports', 'skipped_rows')) {
                $table->integer('skipped_rows')->default(0)->after('updated_rows');
            }
            
            // Add index for better performance
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('csv_imports', function (Blueprint $table) {
            $table->dropColumn([
                'total_rows',
                'processed_rows',
                'success_rows',
                'failed_rows',
                'created_rows',
                'updated_rows',
                'skipped_rows'
            ]);
            
            $table->dropIndex(['user_id', 'status']);
        });
    }
};