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
        Schema::table('members', function (Blueprint $table) {
            $table->string('mobile_phone')->nullable()->after('home_phone');
            $table->date('date_of_birth')->nullable()->after('mobile_phone');
            $table->date('date_of_hire')->nullable()->after('date_of_birth');
            $table->enum('gender', ['male', 'female'])->nullable()->after('date_of_hire');
            $table->string('profile_photo_url')->nullable()->after('gender');
            $table->string('work_phone')->nullable()->change();
            $table->string('work_fax')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['mobile_phone', 'date_of_birth', 'date_of_hire', 'gender', 'profile_photo_url']);

            $table->string('work_phone')->nullable(false)->change();
            $table->string('work_fax')->nullable(false)->change();
        });
    }
};
