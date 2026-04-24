<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('key_hash');
            $table->text('allowed_ips')->nullable(); // comma-separated, supports CIDR and *
            $table->integer('rate_limit_per_hour')->default(360);
            $table->json('scopes')->nullable(); // ["read","write"]
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('api_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->string('ip', 64)->nullable();
            $table->string('method', 8)->nullable();
            $table->string('path', 255)->nullable();
            $table->smallInteger('status')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('requested_at')->useCurrent();
        });
    }
    public function down(): void {
        Schema::dropIfExists('api_access_logs');
        Schema::dropIfExists('api_keys');
    }
};
