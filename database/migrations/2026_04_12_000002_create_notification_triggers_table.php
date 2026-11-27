<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notification_triggers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('event_key');
            $table->unsignedInteger('days_before')->nullable();
            $table->enum('audience', ['admin', 'customer'])->default('customer');
            $table->foreignId('email_template_id')->constrained('email_templates')->cascadeOnDelete();
            $table->boolean('admin_create_halo_ticket')->default(false);
            $table->string('halo_ticket_board')->nullable();
            $table->string('halo_ticket_type')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_triggers');
    }
};
