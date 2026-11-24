<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Halo billing state of an internet service: the subscription (Halo SoftwareLicence) and the recurring invoice line
 * it is billed through, plus the usual sync bookkeeping (timestamp, last error, hash of what was last pushed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_services', function (Blueprint $table) {
            $table->unsignedBigInteger('halo_subscription_id')->nullable()->index();
            $table->unsignedBigInteger('halo_recurring_invoice_id')->nullable();
            $table->unsignedBigInteger('halo_recurring_line_id')->nullable();
            $table->timestamp('halo_synced_at')->nullable();
            $table->string('halo_sync_error', 1000)->nullable();
            $table->string('halo_sync_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('internet_services', function (Blueprint $table) {
            $table->dropIndex(['halo_subscription_id']);
            $table->dropColumn([
                'halo_subscription_id', 'halo_recurring_invoice_id', 'halo_recurring_line_id',
                'halo_synced_at', 'halo_sync_error', 'halo_sync_hash',
            ]);
        });
    }
};
