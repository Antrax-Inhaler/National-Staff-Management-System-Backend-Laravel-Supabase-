<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        $exists = DB::table('officer_positions')
            ->where('name', 'Membership Chair')
            ->exists();

        if (!$exists) {
            $maxOrder = DB::table('officer_positions')->max('display_order') ?? 0;
            
            DB::table('officer_positions')->insert([
                'name' => 'Membership Chair',
                'display_order' => $maxOrder + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            echo "Added 'Membership Chair' to officer_positions table\n";
        } else {
            echo "'Membership Chair' already exists in officer_positions table\n";
        }
    }

    public function down()
    {
        DB::table('officer_positions')
            ->where('name', 'Membership Chair')
            ->delete();
            
        echo "Removed 'Membership Chair' from officer_positions table\n";
    }
};