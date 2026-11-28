<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saml_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('type', 10); // staff | client
            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('entra_tenant_id', 64); // assertions whose tenantid claim differs are rejected
            $table->string('idp_entity_id');
            $table->string('idp_sso_url');
            $table->string('idp_slo_url')->nullable();
            $table->text('idp_x509_cert');
            $table->text('idp_x509_cert_2')->nullable(); // rollover
            $table->string('metadata_url')->nullable();
            $table->json('group_role_map')->nullable(); // [{group_id, role}]
            $table->string('default_role')->nullable();
            $table->boolean('jit_provisioning')->default(true);
            $table->boolean('skip_local_mfa')->default(true);
            $table->timestamps();

            $table->index(['type', 'client_id']);
        });

        Schema::create('saml_connection_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saml_connection_id')->constrained('saml_connections')->cascadeOnDelete();
            $table->string('domain')->unique(); // unique across ALL connections
            $table->timestamps();
        });

        Schema::create('saml_pending_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_id')->unique();
            $table->foreignId('saml_connection_id')->constrained('saml_connections')->cascadeOnDelete();
            $table->string('return_to')->nullable();
            $table->timestamp('expires_at')->index();
        });

        Schema::create('saml_assertion_replays', function (Blueprint $table) {
            $table->id();
            $table->string('assertion_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('saml_connection_id')->nullable()->constrained('saml_connections')->nullOnDelete();
            $table->string('saml_object_id', 64)->nullable();
            $table->string('saml_name_id')->nullable();
            $table->timestamp('saml_last_login_at')->nullable();
            $table->boolean('is_break_glass')->default(false);
            $table->unique(['saml_connection_id', 'saml_object_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['saml_connection_id', 'saml_object_id']);
            $table->dropConstrainedForeignId('saml_connection_id');
            $table->dropColumn(['saml_object_id', 'saml_name_id', 'saml_last_login_at', 'is_break_glass']);
        });
        Schema::dropIfExists('saml_assertion_replays');
        Schema::dropIfExists('saml_pending_requests');
        Schema::dropIfExists('saml_connection_domains');
        Schema::dropIfExists('saml_connections');
    }
};
