<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer tenants of the MSP's Microsoft partner (CSP / GDAP) account, mirrored by "Sync now".
        // The mapping to a DomainDash client lives in clients.microsoft_tenant_id (single source of truth); one tenant
        // maps to one client, enforced by App\Rules\UniqueClientTenant and PartnerTenantMapper (not a DB index, so
        // legacy duplicate data can never fail an upgrade).
        Schema::create('partner_customer_tenants', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 36)->unique();
            $table->string('display_name')->nullable();
            $table->string('default_domain')->nullable();
            $table->json('domains')->nullable();
            $table->string('relationship_status', 32)->nullable(); // active | not_seen (dropped out of the last sync)
            $table->string('source', 32)->nullable();               // contracts | delegated_admin_customers | both
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_customer_tenants');
    }
};
