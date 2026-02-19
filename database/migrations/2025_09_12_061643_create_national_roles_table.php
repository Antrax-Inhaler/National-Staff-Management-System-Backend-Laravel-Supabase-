<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('national_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Insert default roles
        DB::table('national_roles')->insert([
            ['name' => 'Organization Administrator', 'description' => 'Has full administrative access to the system', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'ORG Executive Committee', 'description' => 'Member of the ORG Executive Committee', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'ORG Research Committee', 'description' => 'Member of the ORG Research Committee', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('national_roles');
    }
};