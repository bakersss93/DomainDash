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
        Schema::create('hosting_packages', function (Blueprint $table) {
            $table->id();
            $table->string('package_name')->unique();
            $table->string('category')->nullable();
            $table->integer('disk_mb')->nullable();
            $table->integer('bandwidth_mb')->nullable();
            $table->integer('cpu_percent')->nullable();
            $table->integer('memory_mb')->nullable();
            $table->integer('io_mbps')->nullable();
            $table->integer('inodes_soft')->nullable();
            $table->integer('inodes_hard')->nullable();
            $table->integer('email_accounts')->nullable();
            $table->integer('databases')->nullable();
            $table->boolean('ssh_access')->default(false);
            $table->decimal('price_monthly', 10, 2)->nullable();
            $table->decimal('price_annually', 10, 2)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hosting_packages');
    }
};
