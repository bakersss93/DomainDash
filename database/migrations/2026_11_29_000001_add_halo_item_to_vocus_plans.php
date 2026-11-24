<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Vocus plan links to one HaloPSA Item (GET /api/Item id). Several plans may share one item. The name is a cached
 * label so the plans list needs no Halo call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vocus_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('halo_item_id')->nullable()->index();
            $table->string('halo_item_name', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vocus_plans', function (Blueprint $table) {
            $table->dropIndex(['halo_item_id']);
            $table->dropColumn(['halo_item_id', 'halo_item_name']);
        });
    }
};
