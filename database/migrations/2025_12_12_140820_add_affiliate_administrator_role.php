<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // $exists = DB::table('roles')
        //     ->where('name', 'affiliate_administrator')
        //     ->exists();
        
        // if (!$exists) {
        //     // Get the next available ID
        //     $maxId = DB::table('roles')->max('id');
        //     $nextId = $maxId ? $maxId + 1 : 19;
            
        //     DB::table('roles')->insert([
        //         'id' => $nextId,
        //         'name' => 'affiliate_administrator',
        //         'description' => 'Affiliate Administrator with administrative access to their affiliate',
        //         'created_at' => now(),
        //         'updated_at' => now(),
        //         'default' => false,
        //         'parent_role_id' => null, 
        //     ]);
            
        //     echo "Affiliate Administrator role added with ID: {$nextId}\n";
        // } else {
        //     echo "Affiliate Administrator role already exists\n";
        // }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('roles')
            ->where('name', 'affiliate_administrator')
            ->delete();
            
        echo "Affiliate Administrator role removed\n";
    }
};