<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user "Enable Microsoft sign-in" switch (default off) and the "keep me logged in" choice carried through the
 * SAML round trip (stored with the pending AuthnRequest because the ACS is a cross-site POST without our session).
 * Users that already completed a SAML or Microsoft sign-in keep working: they are switched on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('sso_enabled')->default(false);
        });

        DB::table('users')->where(function ($q) {
            $q->whereNotNull('saml_object_id')->orWhereNotNull('microsoft_oid');
        })->update(['sso_enabled' => true]);

        Schema::table('saml_pending_requests', function (Blueprint $table) {
            $table->boolean('remember')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('saml_pending_requests', function (Blueprint $table) {
            $table->dropColumn('remember');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sso_enabled');
        });
    }
};
