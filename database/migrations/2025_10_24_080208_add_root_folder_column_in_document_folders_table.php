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
        Schema::table('document_folders', function (Blueprint $table) {
            $table->boolean('root')->nullable()->default(false);

            $table->unique(['affiliate_id'], 'unique_root_per_affiliate')->where('root', true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_folders', function (Blueprint $table) {
            $table->boolean('root')->nullable()->default(false);
        });
    }
};
