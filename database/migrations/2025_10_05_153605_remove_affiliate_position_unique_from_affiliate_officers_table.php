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
        Schema::table('affiliate_officers', function (Blueprint $table) {
            // First, we need to drop and recreate the foreign keys without the constraint
            $table->dropForeign(['affiliate_id']);
            $table->dropForeign(['position_id']);
            
            // Drop the unique constraint
            $table->dropUnique('affiliate_officers_affiliate_id_position_id_unique');
            
            // Recreate foreign keys without the unique constraint requirement
            $table->foreign('affiliate_id')
                ->references('id')
                ->on('affiliates');
                
            $table->foreign('position_id')
                ->references('id')
                ->on('officer_positions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('affiliate_officers', function (Blueprint $table) {
            // To reverse, we need to ensure data is unique first
            // This might fail if you have duplicate entries
            
            $table->dropForeign(['affiliate_id']);
            $table->dropForeign(['position_id']);
            
            $table->unique(['affiliate_id', 'position_id']);
            
            $table->foreign('affiliate_id')
                ->references('id')
                ->on('affiliates');
                
            $table->foreign('position_id')
                ->references('id')
                ->on('officer_positions');
        });
    }
};