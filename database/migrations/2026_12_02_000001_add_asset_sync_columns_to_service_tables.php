<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset (documentation) sync state for the non-domain entities, one set per remote system.
 * Domains already carry halo_asset_id / itglue_id and halo_* / itglue_* sync columns.
 *
 * internet_services bills through HaloSubscriptionPusher and already uses halo_synced_at / halo_sync_error /
 * halo_sync_hash for that, so the asset columns are named halo_asset_* on every new table (one naming scheme,
 * never mixed up with billing).
 */
return new class extends Migration
{
    private const TABLES = ['internet_services', 'hosting_services', 'ssl_certificates'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                if (! Schema::hasColumn($name, 'halo_asset_id')) {
                    $table->unsignedBigInteger('halo_asset_id')->nullable()->index();
                    $table->timestamp('halo_asset_synced_at')->nullable();
                    $table->text('halo_asset_sync_error')->nullable();
                    $table->string('halo_asset_sync_hash', 64)->nullable();
                }
                if (! Schema::hasColumn($name, 'itglue_id')) {
                    $table->unsignedBigInteger('itglue_id')->nullable()->index();
                    $table->timestamp('itglue_synced_at')->nullable();
                    $table->text('itglue_sync_error')->nullable();
                    $table->string('itglue_sync_hash', 64)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['halo_asset_id']);
                $table->dropIndex(['itglue_id']);
                $table->dropColumn([
                    'halo_asset_id', 'halo_asset_synced_at', 'halo_asset_sync_error', 'halo_asset_sync_hash',
                    'itglue_id', 'itglue_synced_at', 'itglue_sync_error', 'itglue_sync_hash',
                ]);
            });
        }
    }
};
