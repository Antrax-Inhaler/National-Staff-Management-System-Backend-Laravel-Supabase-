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
        if (!Schema::hasTable('help_videos')) {
            Schema::create('help_videos', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('public_uid', 50)->unique()->nullable();
                $table->string('title', 255);
                $table->text('description')->nullable();
                $table->string('video_url', 500);
                $table->string('thumbnail_url', 500)->nullable();
                $table->string('duration', 20)->nullable();
                $table->string('category', 100)->default('General');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->integer('view_count')->default(0);
                $table->boolean('is_active')->default(true);
            });

            DB::statement('ALTER TABLE help_videos ADD CONSTRAINT help_videos_created_by_fkey FOREIGN KEY (created_by) REFERENCES users(id)');

            DB::statement('ALTER TABLE help_videos ALTER COLUMN public_uid SET DEFAULT (gen_random_uuid())::character varying(50)');

            DB::statement('CREATE INDEX idx_help_videos_category ON help_videos(category)');
            DB::statement('CREATE INDEX idx_help_videos_created_at ON help_videos(created_at DESC)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('help_videos');
    }
};