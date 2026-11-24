<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The Halo recurring invoice a client's internet services are billed on (picked per client, wins over the contract strategy). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedBigInteger('halo_recurring_invoice_id')->nullable();
            $table->string('halo_recurring_invoice_name', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['halo_recurring_invoice_id', 'halo_recurring_invoice_name']);
        });
    }
};
