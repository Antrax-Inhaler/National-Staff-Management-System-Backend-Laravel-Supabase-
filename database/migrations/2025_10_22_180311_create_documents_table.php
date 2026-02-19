<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->text('keywords')->nullable();
            $table->string('type')->nullable();
            $table->jsonb('category')->nullable();

            $table->foreignId('folder_id')->nullable()->constrained('document_folders')->cascadeOnDelete();
            $table->foreignId('affiliate_id')->nullable()->constrained('affiliates')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();


            $table->string('file_name')->nullable();
            $table->string('file_path')->nullable();
            $table->integer('file_size')->nullable();

            $table->string('employer')->nullable();
            $table->string('cbc')->nullable();
            $table->string('state')->nullable();
            $table->string('status')->nullable();
            $table->string('sub_type')->nullable();
            $table->year('year')->nullable();
            $table->date('expiration_date')->nullable();
            $table->date('effective_date')->nullable();
            $table->string('folder_name')->nullable();
            $table->string('uploaded_by')->nullable();

            $table->text('content_extract')->nullable();

            $table->string('database_source')->nullable();
            $table->boolean('is_active')->nullable()->default(true);
            $table->boolean('is_archived')->nullable()->default(false);
            $table->boolean('is_public')->nullable()->default(false);

            $table->timestamps();
        });


        DB::statement("ALTER TABLE documents ADD COLUMN search_vector tsvector");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
