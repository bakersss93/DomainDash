<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Local mirror of Halo clients: feeds the import modal and mapping UI.
        Schema::create('halo_clients', function (Blueprint $table) {
            $table->unsignedBigInteger('halo_id')->primary();
            $table->string('name')->index();
            $table->string('abn', 64)->nullable();
            $table->boolean('inactive')->default(false);
            $table->timestamp('remote_updated_at')->nullable();
            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('halo_clients');
    }
};
