<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internet_service_diagnostics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internet_service_id')->constrained()->cascadeOnDelete();
            $table->string('diagnostic_type', 30); // AUTH-LOG / DISCONNECT / NOTIFICATIONS
            $table->string('transaction_id', 20)->nullable();
            $table->string('transaction_state', 20)->nullable();
            $table->json('result')->nullable();
            $table->foreignId('run_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internet_service_diagnostics');
    }
};
