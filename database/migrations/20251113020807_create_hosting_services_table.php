<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('hosting_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained('domains')->nullOnDelete();
            $table->string('hoid')->nullable();
            $table->string('plan')->nullable();
            $table->string('username')->nullable();
            $table->string('server')->nullable();
            $table->integer('disk_limit_mb')->nullable();
            $table->integer('disk_usage_mb')->nullable();
            $table->integer('bandwidth_limit_mb')->nullable();
            $table->integer('bandwidth_used_mb')->nullable();
            $table->string('ip_address')->nullable();
            $table->date('next_renewal_due')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('hosting_services');
    }
};
