<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-service Halo billing choice and the agreement last used.
 *  - halo_override_invoice_id / _name: the recurring invoice picked explicitly on the service page (wins over every rule)
 *  - halo_contract_id / halo_contract_ref: the agreement (ClientContract) the recurring invoice belongs to, shown on the card
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internet_services', function (Blueprint $table) {
            $table->unsignedBigInteger('halo_override_invoice_id')->nullable();
            $table->string('halo_override_invoice_name', 255)->nullable();
            $table->unsignedBigInteger('halo_contract_id')->nullable();
            $table->string('halo_contract_ref', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('internet_services', function (Blueprint $table) {
            $table->dropColumn(['halo_override_invoice_id', 'halo_override_invoice_name', 'halo_contract_id', 'halo_contract_ref']);
        });
    }
};
