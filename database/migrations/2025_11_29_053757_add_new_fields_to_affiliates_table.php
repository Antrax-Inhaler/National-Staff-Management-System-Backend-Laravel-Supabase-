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
        Schema::table('affiliates', function (Blueprint $table) {
            $table->string('state', 2)->nullable()->after('name');
            
            $table->string('employer_name')->nullable()->after('state');
            
            $table->string('ein', 20)->nullable()->after('employer_name');
            
            $table->date('affiliation_date')->nullable()->after('ein');
            
            $table->string('logo_url')->nullable()->after('affiliation_date');
            
            $table->enum('affiliate_type', ['Associate', 'Professional', 'Wall-to-Wall'])->nullable()->after('logo_url');
            
            $table->enum('cbc_region', ['Northeast', 'Cooridor', 'South', 'Central', 'Western'])->nullable()->after('affiliate_type');
            
            $table->string('org_region', 10)->nullable()->after('cbc_region');
            
            $table->index('state');
            $table->index('affiliate_type');
            $table->index('cbc_region');
            $table->index('org_region');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            $table->dropColumn([
                'state',
                'employer_name',
                'ein',
                'affiliation_date',
                'logo_url',
                'affiliate_type',
                'cbc_region',
                'org_region'
            ]);
        });
    }
};