<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-user dismiss/snooze state for generated system alerts. A row hides the alert until snoozed_until;
        // if the underlying condition is still true afterwards the alert reappears.
        Schema::create('notification_alert_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('alert_key', 120);
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'alert_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_alert_states');
    }
};
