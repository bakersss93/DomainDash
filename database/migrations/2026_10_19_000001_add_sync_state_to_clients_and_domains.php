<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Lookups by Halo / IT Glue reference run on every sync and import.
            $table->index('halopsa_reference', 'clients_halopsa_reference_index');
            $table->index('itglue_org_id', 'clients_itglue_org_id_index');

            $table->timestamp('halo_synced_at')->nullable();
            $table->timestamp('itglue_synced_at')->nullable();
            // Cached default site so a push never needs GET client/{id} per domain.
            $table->unsignedBigInteger('halo_default_site_id')->nullable();
        });

        // domains.halo_asset_id / domains.itglue_id are already indexed by
        // 2025_11_27_232935_add_halo_itglue_id_to_domains_table.
        Schema::table('domains', function (Blueprint $table) {
            $table->timestamp('halo_synced_at')->nullable();
            $table->timestamp('itglue_synced_at')->nullable();
            // Hash of the last payload pushed; identical payloads are skipped.
            $table->string('halo_sync_hash', 64)->nullable();
            $table->string('itglue_sync_hash', 64)->nullable();
            $table->text('halo_sync_error')->nullable();
            $table->text('itglue_sync_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn([
                'halo_synced_at', 'itglue_synced_at',
                'halo_sync_hash', 'itglue_sync_hash',
                'halo_sync_error', 'itglue_sync_error',
            ]);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex('clients_halopsa_reference_index');
            $table->dropIndex('clients_itglue_org_id_index');
            $table->dropColumn(['halo_synced_at', 'itglue_synced_at', 'halo_default_site_id']);
        });
    }
};
