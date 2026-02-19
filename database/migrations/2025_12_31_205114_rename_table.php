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
        // Rename the table
        Schema::rename('national_informations', 'national_information');

        // Update foreign key constraints that reference this table
        // Example: if 'documents' table has 'document_folder_id' FK
        Schema::table('national_information_attachments', function (Blueprint $table) {
            $table->dropForeign(['national_info_id']); // drop old FK

            $table->foreign('national_info_id')
                ->references('id')
                ->on('national_information') // new table name
                ->onDelete('cascade'); // maintain cascade
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rename the table
        Schema::rename('national_informations', 'national_information');

        // Update foreign key constraints that reference this table
        // Example: if 'documents' table has 'document_folder_id' FK
        Schema::table('national_information_attachments', function (Blueprint $table) {
            $table->dropForeign(['national_info_id']); // drop old FK

            $table->foreign('national_info_id')
                ->references('id')
                ->on('national_information') // new table name
                ->onDelete('cascade'); // maintain cascade
        });
    }
};
