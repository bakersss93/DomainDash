<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Local mirror of Halo domain assets: pages read this, never Halo.
        Schema::create('halo_assets', function (Blueprint $table) {
            $table->unsignedBigInteger('halo_id')->primary();
            $table->unsignedBigInteger('halo_client_id')->index();
            $table->unsignedInteger('assettype_id')->default(0)->index();
            $table->string('assettype_name')->nullable();
            $table->string('name')->nullable();
            $table->string('inventory_number')->nullable()->index();
            $table->timestamp('remote_updated_at')->nullable()->index();
            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('halo_assets');
    }
};
