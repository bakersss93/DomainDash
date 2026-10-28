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
        // Add columns to domains table
        Schema::table('domains', function (Blueprint $table) {
            // HaloPSA asset ID
            if (!Schema::hasColumn('domains', 'halo_asset_id')) {
                $table->unsignedBigInteger('halo_asset_id')->nullable()->after('client_id');
                $table->index('halo_asset_id');
            }
            
            // ITGlue domain ID
            if (!Schema::hasColumn('domains', 'itglue_id')) {
                $table->unsignedBigInteger('itglue_id')->nullable()->after('halo_asset_id');
                $table->index('itglue_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            if (Schema::hasColumn('domains', 'halo_asset_id')) {
                $table->dropIndex(['halo_asset_id']);
                $table->dropColumn('halo_asset_id');
            }
            
            if (Schema::hasColumn('domains', 'itglue_id')) {
                $table->dropIndex(['itglue_id']);
                $table->dropColumn('itglue_id');
            }
        });
    }
};