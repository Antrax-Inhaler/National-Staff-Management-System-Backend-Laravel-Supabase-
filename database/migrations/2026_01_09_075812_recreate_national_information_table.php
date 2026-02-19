<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop the old type constraint if it exists
        if (Schema::hasTable('national_information')) {
            try {
                DB::statement('ALTER TABLE national_information DROP CONSTRAINT IF EXISTS national_information_type_check');
            } catch (\Exception $e) {
                // Try different constraint names
                try {
                    DB::statement('ALTER TABLE national_information DROP CONSTRAINT IF EXISTS national_information_type_check1');
                } catch (\Exception $e) {
                    // Constraint might not exist or has different name
                }
            }
            
            // Add the new type constraint with all allowed values
            DB::statement("
                ALTER TABLE national_information 
                ADD CONSTRAINT national_information_type_check 
                CHECK (type IN ('announcement', 'policy', 'report', 'update', 'news', 'resource', 'event'))
            ");
            
            // Ensure status constraint exists
            try {
                DB::statement('ALTER TABLE national_information DROP CONSTRAINT IF EXISTS national_information_status_check');
            } catch (\Exception $e) {
                // Constraint might not exist
            }
            
            DB::statement("
                ALTER TABLE national_information 
                ADD CONSTRAINT national_information_status_check 
                CHECK (status IN ('draft', 'published', 'archived'))
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('national_information')) {
            // Drop the constraints
            try {
                DB::statement('ALTER TABLE national_information DROP CONSTRAINT IF EXISTS national_information_type_check');
            } catch (\Exception $e) {
                // Ignore if constraint doesn't exist
            }
            
            try {
                DB::statement('ALTER TABLE national_information DROP CONSTRAINT IF EXISTS national_information_status_check');
            } catch (\Exception $e) {
                // Ignore if constraint doesn't exist
            }
            
            // Re-add the original type constraint (if you want to revert to original)
            DB::statement("
                ALTER TABLE national_information 
                ADD CONSTRAINT national_information_type_check 
                CHECK (type IN ('announcement', 'policy', 'report', 'update'))
            ");
        }
    }
};