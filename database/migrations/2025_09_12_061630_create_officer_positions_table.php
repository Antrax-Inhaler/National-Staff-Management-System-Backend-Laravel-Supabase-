<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('officer_positions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });

        // Insert default positions
        DB::table('officer_positions')->insert([
            ['name' => 'President', 'display_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Vice President', 'display_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Secretary', 'display_order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Treasurer', 'display_order' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Grievance Chair', 'display_order' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Bargaining Chair', 'display_order' => 6, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('officer_positions');
    }
};