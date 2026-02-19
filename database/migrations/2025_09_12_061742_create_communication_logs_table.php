<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_logs', function (Blueprint $table) {
            $table->id();
            $table->string('subject')->nullable();
            $table->text('message');
            $table->string('communication_type');
            $table->text('sent_to')->nullable();
            $table->foreignId('sent_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('affiliate_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->string('status')->default('Queued');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_logs');
    }
};