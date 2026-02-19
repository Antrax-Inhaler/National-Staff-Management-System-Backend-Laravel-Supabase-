<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('csv_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('filename');
            $table->string('file_path');
            $table->integer('total_rows')->default(0);
            $table->integer('processed_rows')->default(0);
            $table->integer('success_rows')->default(0);
            $table->integer('failed_rows')->default(0);
            $table->integer('chunk_size')->default(1000);
            $table->integer('total_chunks')->default(0);
            $table->integer('processed_chunks')->default(0);
            $table->integer('current_chunk_index')->default(0);
            $table->enum('status', [
                'pending', 'processing', 'paused', 'completed', 'failed', 'stopped'
            ])->default('pending');
            $table->json('control_flags')->nullable();
            $table->json('details')->nullable();
            $table->json('errors')->nullable();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['status', 'user_id']);
            $table->index('created_at');
        });
        
        Schema::create('csv_import_chunks', function (Blueprint $table) {
            $table->id();
            $table->uuid('import_id');
            $table->integer('chunk_index');
            $table->enum('status', [
                'pending', 'processing', 'completed', 'failed', 'paused', 'stopped'
            ])->default('pending');
            $table->integer('total_rows')->default(0);
            $table->integer('processed_rows')->default(0);
            $table->integer('success_rows')->default(0);
            $table->integer('failed_rows')->default(0);
            $table->integer('start_row');
            $table->integer('end_row');
            $table->json('details')->nullable();
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('processing_duration')->nullable()->comment('Duration in seconds');
            $table->timestamps();
            
            $table->foreign('import_id')->references('id')->on('csv_imports')->onDelete('cascade');
            $table->index(['import_id', 'status']);
            $table->index(['import_id', 'chunk_index']);
            $table->unique(['import_id', 'chunk_index']);
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('csv_import_chunks');
        Schema::dropIfExists('csv_imports');
    }
};