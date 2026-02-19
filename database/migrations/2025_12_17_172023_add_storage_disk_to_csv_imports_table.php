<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add to csv_imports table
        if (Schema::hasTable('csv_imports') && !Schema::hasColumn('csv_imports', 'storage_disk')) {
            Schema::table('csv_imports', function (Blueprint $table) {
                $table->string('storage_disk')->default('supabase')->after('file_path');
            });
        }
        
        // Add to csv_import_chunks table (optional)
        if (Schema::hasTable('csv_import_chunks') && !Schema::hasColumn('csv_import_chunks', 'storage_disk')) {
            Schema::table('csv_import_chunks', function (Blueprint $table) {
                $table->string('storage_disk')->nullable()->after('import_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('csv_imports', function (Blueprint $table) {
            $table->dropColumn('storage_disk');
        });
        
        Schema::table('csv_import_chunks', function (Blueprint $table) {
            $table->dropColumn('storage_disk');
        });
    }
};