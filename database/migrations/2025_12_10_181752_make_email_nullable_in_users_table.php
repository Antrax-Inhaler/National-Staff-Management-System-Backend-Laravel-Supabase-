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
        Schema::table('users', function (Blueprint $table) {
            // Make email column nullable
            $table->string('email')->nullable()->change();
            
            // Optional: If you also want to make the unique constraint conditional
            // You might want to create a partial unique index instead
            // $table->dropUnique(['email']);
            // $table->unique(['email'], 'users_email_unique')->where('email IS NOT NULL');
        });
        
        // If you're using PostgreSQL, you might need this
        // DB::statement('ALTER TABLE users DROP CONSTRAINT users_email_unique');
        // DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE email IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // First, clean up any null values if they exist
            DB::table('users')->whereNull('email')->update(['email' => DB::raw("CONCAT('no-email-', id, '@placeholder.com')")]);
            
            // Make email not nullable again
            $table->string('email')->nullable(false)->change();
        });
    }
};