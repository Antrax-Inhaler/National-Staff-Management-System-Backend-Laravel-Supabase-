<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('affiliate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('member_id')->unique()->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('level')->nullable();
            $table->string('employment_status')->nullable();
            $table->string('status')->nullable();
            $table->text('address_line1')->nullable();
            $table->text('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip_code', 10)->nullable();
            $table->string('work_email')->nullable();
            $table->string('work_phone', 20)->nullable();
            $table->string('work_fax', 20)->nullable();
            $table->string('home_email')->nullable();
            $table->string('home_phone', 20)->nullable();
            $table->string('self_id')->nullable();
            $table->boolean('non_nso')->default(false);
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};