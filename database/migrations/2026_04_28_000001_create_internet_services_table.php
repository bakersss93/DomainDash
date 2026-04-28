<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internet_services', function (Blueprint $table) {
            $table->id();
            $table->string('vocus_service_id', 30)->unique()->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('plan_id', 30)->nullable();
            $table->string('service_scope', 30)->nullable();   // RESELLER-CONNECT / NETWORK-CONNECT
            $table->string('service_status', 10)->default('ACTIVE'); // ACTIVE / INACTIVE / SUSPEND
            $table->string('service_type', 20)->nullable();    // FTTC / FTTP / FTTB / FTTN / HFC / FIXED-WIRELESS
            $table->string('order_type', 10)->nullable();      // NEW / CHURN
            $table->string('customer_name', 100)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('directory_id', 30)->nullable();    // Vocus LOC address ID
            $table->string('location_reference', 10)->nullable(); // postcode
            $table->string('address_long', 255)->nullable();   // human-readable from DIR lookup
            $table->string('nbn_instance_id', 30)->nullable(); // PRI number
            $table->string('avc_id', 30)->nullable();
            $table->string('cvc_id', 30)->nullable();
            $table->string('copper_pair_id', 30)->nullable();  // FTTC / FTTN
            $table->string('realm', 64)->nullable();           // PPPoE realm
            $table->string('service_level', 20)->nullable();
            $table->string('billing_provider_id', 30)->nullable();
            $table->string('last_transaction_id', 20)->nullable();
            $table->string('last_transaction_state', 20)->nullable(); // QUEUED / PROCESSING / SUCCESS / FAILED
            $table->text('notes')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internet_services');
    }
};
