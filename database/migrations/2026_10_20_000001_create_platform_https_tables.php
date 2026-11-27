<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Work queue between the web app (writes) and the root helper (reads/updates).
        Schema::create('platform_certificate_requests', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);                       // letsencrypt | upload | renew
            $table->string('hostname', 253)->nullable();
            $table->string('email', 254)->nullable();
            $table->boolean('staging')->default(false);
            $table->string('status', 20)->default('pending'); // pending | running | succeeded | failed
            // Uploads only: Crypt-encrypted JSON {cert, chain, key}. Wiped by the helper once installed.
            $table->longText('payload')->nullable();
            $table->text('log')->nullable();                  // sanitized tail, never secrets
            $table->string('error', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
        });

        Schema::create('platform_certificates', function (Blueprint $table) {
            $table->id();
            $table->string('hostname', 253);
            $table->string('source', 20);                     // letsencrypt | upload
            $table->string('issuer', 500)->nullable();
            $table->string('subject', 500)->nullable();
            $table->json('sans')->nullable();
            $table->string('fingerprint_sha256', 95)->nullable();
            $table->string('key_type', 20)->nullable();
            $table->timestamp('not_before')->nullable();
            $table->timestamp('not_after')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('auto_renew')->default(false);
            $table->boolean('staging')->default(false);
            $table->timestamp('last_renewed_at')->nullable();
            // Uploads only. Public material in clear; the private key is Crypt-encrypted.
            $table->longText('certificate_pem')->nullable();
            $table->longText('chain_pem')->nullable();
            $table->longText('private_key_encrypted')->nullable();
            $table->foreignId('request_id')->nullable()->constrained('platform_certificate_requests')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active']);
        });

        // Bridges the certbot auth hook and cleanup hook (separate processes).
        Schema::create('platform_acme_challenges', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 253);
            $table->string('zone', 253);
            $table->string('record_name', 253);
            $table->string('value', 128);
            $table->string('record_id', 128)->nullable();
            $table->timestamps();
            $table->index(['domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_acme_challenges');
        Schema::dropIfExists('platform_certificates');
        Schema::dropIfExists('platform_certificate_requests');
    }
};
