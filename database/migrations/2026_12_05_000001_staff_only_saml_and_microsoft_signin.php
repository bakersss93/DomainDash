<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - SAML becomes staff-only: any client-type connection is removed (defensively; none exist in production),
 *   users that were linked through one lose that link, and the client_id binding column goes away.
 * - is_break_glass becomes the general per-user "may also use password" exception (password_login_allowed).
 * - Client Microsoft sign-in (OIDC): per-user (tenant, object id) link and per-client tenant lock / login-method override.
 * - The old "enforce_staff" SSO setting is converted into the new per-audience login policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Remove client-type SAML connections and every trace of identities linked through them.
        $clientIds = DB::table('saml_connections')->where('type', '!=', 'staff')->pluck('id')->all();
        if ($clientIds !== []) {
            DB::table('users')->whereIn('saml_connection_id', $clientIds)->update([
                'saml_connection_id' => null, 'saml_object_id' => null, 'saml_name_id' => null,
            ]);
            DB::table('saml_pending_requests')->whereIn('saml_connection_id', $clientIds)->delete();
            DB::table('saml_connection_domains')->whereIn('saml_connection_id', $clientIds)->delete();
            DB::table('saml_connections')->whereIn('id', $clientIds)->delete();
        }

        if (Schema::hasColumn('saml_connections', 'client_id')) {
            Schema::table('saml_connections', function (Blueprint $table) {
                $table->dropIndex(['type', 'client_id']);
            });
            Schema::table('saml_connections', function (Blueprint $table) {
                $table->dropConstrainedForeignId('client_id');
            });
        }

        // 2. Break-glass flag -> general password exception.
        if (Schema::hasColumn('users', 'is_break_glass') && ! Schema::hasColumn('users', 'password_login_allowed')) {
            Schema::table('users', function (Blueprint $table) {
                $table->renameColumn('is_break_glass', 'password_login_allowed');
            });
        }

        // 3. Client Microsoft sign-in.
        Schema::table('users', function (Blueprint $table) {
            $table->string('microsoft_oid', 64)->nullable();
            $table->string('microsoft_tid', 64)->nullable();
            $table->timestamp('microsoft_linked_at')->nullable();
            $table->timestamp('microsoft_last_login_at')->nullable();
            $table->unique(['microsoft_tid', 'microsoft_oid']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('microsoft_tenant_id', 64)->nullable();
            $table->string('login_method', 16)->default('inherit'); // inherit | both | microsoft_only
        });

        // 4. enforce_staff -> staff_login.
        $sso = (array) Setting::get('sso', []);
        if (array_key_exists('enforce_staff', $sso)) {
            $sso['staff_login'] = $sso['enforce_staff'] ? 'microsoft_only' : 'both';
            unset($sso['enforce_staff']);
            Setting::put('sso', $sso);
        }
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['microsoft_tenant_id', 'login_method']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['microsoft_tid', 'microsoft_oid']);
            $table->dropColumn(['microsoft_oid', 'microsoft_tid', 'microsoft_linked_at', 'microsoft_last_login_at']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('password_login_allowed', 'is_break_glass');
        });
        Schema::table('saml_connections', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->index(['type', 'client_id']);
        });
    }
};
