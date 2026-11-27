<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\SamlConnection;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Saml\ConnectionValidator;
use App\Services\Saml\MetadataImporter;
use App\Services\Saml\SamlException;
use App\Services\Saml\SamlService;
use App\Services\Saml\SsoPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/** Administrator-only (route group `role:Administrator`). */
class SamlConnectionsController extends Controller
{
    public function __construct(private ConnectionValidator $validator, private MetadataImporter $importer, private SamlService $saml)
    {
    }

    public function index()
    {
        $connections = SamlConnection::query()->with(['client', 'domains'])->withCount('users')->orderBy('type')->orderBy('name')->get();
        $admins = User::role('Administrator')->where('is_active', true)->orderBy('name')->get();

        return view('admin.sso.index', [
            'connections' => $connections,
            'sso' => SsoPolicy::settings(),
            'admins' => $admins,
        ]);
    }

    public function create(Request $request)
    {
        $type = $request->query('type') === 'client' ? SamlConnection::TYPE_CLIENT : SamlConnection::TYPE_STAFF;
        $connection = new SamlConnection([
            'type' => $type,
            'client_id' => $type === 'client' ? $request->query('client_id') : null,
            'jit_provisioning' => true,
            // Staff connections default to local MFA ON (skip = false); client tenants are trusted to enforce their own MFA.
            'skip_local_mfa' => $type === SamlConnection::TYPE_CLIENT,
            'enabled' => false,
        ]);

        return view('admin.sso.form', $this->formData($connection));
    }

    public function store(Request $request)
    {
        [$data, $errors] = $this->prepare($request, null);
        $this->throwIf($errors);

        $connection = DB::transaction(function () use ($data) {
            $c = SamlConnection::create(collect($data)->except('domains')->all());
            $c->syncDomains($data['domains']);

            return $c;
        });

        AuditLogger::logAction('sso.connection.created', $connection, "Created SSO connection {$connection->name} ({$connection->type})", [
            'new_values' => $this->auditValues($connection),
        ]);

        return redirect()->route('admin.sso.edit', $connection)->with('status', 'Connection created. Paste the values below into Microsoft Entra, then enable it.');
    }

    public function edit(SamlConnection $connection)
    {
        return view('admin.sso.form', $this->formData($connection));
    }

    public function update(Request $request, SamlConnection $connection)
    {
        [$data, $errors] = $this->prepare($request, $connection);
        $this->throwIf($errors);

        $old = $this->auditValues($connection);
        DB::transaction(function () use ($data, $connection) {
            // type and client binding never change after creation.
            $connection->update(collect($data)->except(['domains', 'type', 'client_id'])->all());
            $connection->syncDomains($data['domains']);
        });

        AuditLogger::logAction('sso.connection.updated', $connection, "Updated SSO connection {$connection->name}", [
            'old_values' => $old,
            'new_values' => $this->auditValues($connection->fresh()),
        ]);

        return redirect()->route('admin.sso.edit', $connection)->with('status', 'Connection saved.');
    }

    public function destroy(SamlConnection $connection)
    {
        $old = $this->auditValues($connection);
        $name = $connection->name;
        // Users keep their local account but lose the SSO link (FK nullOnDelete).
        $connection->delete();

        AuditLogger::logSystem('sso.connection.deleted', "Deleted SSO connection {$name}", ['function' => 'sso'], ['old_values' => $old]);

        return redirect()->route('admin.sso.index')->with('status', 'Connection deleted.');
    }

    /** Re-read the IdP metadata URL (certificate rollover). */
    public function refresh(SamlConnection $connection)
    {
        abort_unless($connection->metadata_url, 422, 'No metadata URL is stored for this connection.');

        try {
            $meta = $this->importer->fromUrl($connection->metadata_url);
        } catch (SamlException $e) {
            return back()->withErrors(['metadata_url' => $e->getMessage()]);
        }

        if ($meta['entra_tenant_id'] && $meta['entra_tenant_id'] !== strtolower($connection->entra_tenant_id)) {
            return back()->withErrors(['metadata_url' => 'The metadata belongs to a different Entra tenant; refusing to apply it.']);
        }

        $old = $this->auditValues($connection);
        $connection->update(collect($meta)->only(['idp_entity_id', 'idp_sso_url', 'idp_slo_url', 'idp_x509_cert', 'idp_x509_cert_2'])->all());
        AuditLogger::logAction('sso.connection.metadata_refreshed', $connection, "Refreshed IdP metadata for {$connection->name}", [
            'old_values' => $old,
            'new_values' => $this->auditValues($connection->fresh()),
        ]);

        return back()->with('status', 'IdP metadata refreshed.');
    }

    /** Offline configuration check (no sign-in is performed). */
    public function check(SamlConnection $connection)
    {
        $problems = [];
        $info = [];

        try {
            $this->saml->metadataXml($connection);
            new \OneLogin\Saml2\Settings($this->saml->settingsArray($connection));
        } catch (\Throwable $e) {
            $problems[] = 'Settings are invalid: '.$e->getMessage();
        }

        foreach (['idp_x509_cert' => 'Signing certificate', 'idp_x509_cert_2' => 'Second signing certificate'] as $f => $label) {
            if (! $connection->$f) {
                continue;
            }
            $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($connection->$f, 64, "\n")."-----END CERTIFICATE-----\n";
            $p = @openssl_x509_parse($pem);
            if (! $p) {
                $problems[] = "{$label} cannot be parsed.";
                continue;
            }
            $until = \Illuminate\Support\Carbon::createFromTimestamp($p['validTo_time_t']);
            $info[] = "{$label} expires {$until->toDateString()}";
            if ($until->isPast()) {
                $problems[] = "{$label} has expired.";
            } elseif ($until->lt(now()->addDays(30))) {
                $problems[] = "{$label} expires within 30 days.";
            }
        }

        if ($connection->domains()->count() === 0) {
            $problems[] = 'No allowed email domains are configured.';
        }
        if (! $connection->group_role_map && ! $connection->default_role) {
            $problems[] = 'No group mapping and no default role: nobody will be able to sign in.';
        }

        return back()->with($problems ? 'sso_check_problems' : 'status', $problems ?: 'Configuration looks good. '.implode('; ', $info).'. Use "Test sign-in" to try it end to end.')
            ->with('sso_check_info', $info);
    }

    /** "Enforce SSO for staff" and the break-glass accounts. */
    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'enforce_staff' => 'nullable|boolean',
            'break_glass' => 'nullable|array',
            'break_glass.*' => 'integer|exists:users,id',
        ]);

        $ids = array_map('intval', $data['break_glass'] ?? []);
        $enforce = (bool) ($data['enforce_staff'] ?? false);

        $candidates = User::query()->whereIn('id', $ids)->get();
        foreach ($candidates as $u) {
            if (! $u->hasRole('Administrator') || ! $u->is_active) {
                throw ValidationException::withMessages(['break_glass' => "{$u->email} must be an active Administrator to be a break-glass account."]);
            }
            if ($u->saml_connection_id !== null) {
                throw ValidationException::withMessages(['break_glass' => "{$u->email} is linked to single sign-on; a break-glass account must be local-only."]);
            }
        }

        if ($enforce) {
            if ($candidates->isEmpty()) {
                throw ValidationException::withMessages(['enforce_staff' => 'Choose at least one break-glass administrator before enforcing SSO, or you could lock everyone out.']);
            }
            if (! SamlConnection::query()->where('type', 'staff')->where('enabled', true)->exists()) {
                throw ValidationException::withMessages(['enforce_staff' => 'Enable a staff SSO connection before enforcing SSO.']);
            }
        }

        $before = SsoPolicy::settings();
        $beforeIds = User::query()->where('is_break_glass', true)->pluck('id')->all();

        DB::transaction(function () use ($ids, $enforce) {
            User::query()->where('is_break_glass', true)->whereNotIn('id', $ids)->update(['is_break_glass' => false]);
            User::query()->whereIn('id', $ids)->update(['is_break_glass' => true]);
            Setting::put('sso', array_merge(SsoPolicy::settings(), ['enforce_staff' => $enforce]));
        });

        AuditLogger::logSystem('sso.settings.updated', 'Updated SSO enforcement settings', ['function' => 'sso'], [
            'old_values' => ['enforce_staff' => $before['enforce_staff'], 'break_glass_user_ids' => $beforeIds],
            'new_values' => ['enforce_staff' => $enforce, 'break_glass_user_ids' => $ids],
        ]);

        return back()->with('status', 'Sign-in policy saved.');
    }

    // ---------------------------------------------------------------------------------------------------

    private function prepare(Request $request, ?SamlConnection $existing): array
    {
        $in = $request->all();
        if ($existing) {
            $in['type'] = $existing->type;
            $in['client_id'] = $existing->client_id;
        }

        // Metadata import (pasted XML wins over URL fetch). Imported values overwrite the typed IdP fields.
        $importErrors = [];
        try {
            $meta = null;
            if (trim((string) $request->input('metadata_xml')) !== '') {
                $meta = $this->importer->fromXml((string) $request->input('metadata_xml'));
            } elseif ($request->boolean('fetch_metadata') && trim((string) $request->input('metadata_url')) !== '') {
                $meta = $this->importer->fromUrl((string) $request->input('metadata_url'));
            }
            if ($meta) {
                $in = array_merge($in, array_filter($meta, fn ($v) => $v !== null && $v !== ''));
                if (empty($request->input('entra_tenant_id')) && ! empty($meta['entra_tenant_id'])) {
                    $in['entra_tenant_id'] = $meta['entra_tenant_id'];
                }
            }
        } catch (SamlException $e) {
            $importErrors['metadata_url'] = $e->getMessage();
        }

        $in['enabled'] = $request->boolean('enabled');
        $in['jit_provisioning'] = $request->boolean('jit_provisioning');
        $in['skip_local_mfa'] = $request->boolean('skip_local_mfa');

        [$data, $errors] = $this->validator->validate($in, $existing);

        return [$data, $importErrors + $errors];
    }

    private function throwIf(array $errors): void
    {
        if ($errors) {
            throw ValidationException::withMessages(array_map(fn ($m) => [$m], $errors));
        }
    }

    private function formData(SamlConnection $c): array
    {
        return [
            'connection' => $c,
            'clients' => Client::query()->orderBy('business_name')->get(['id', 'business_name']),
            'roles' => Role::query()->where('guard_name', 'web')->orderBy('name')->pluck('name'),
            'customerRoles' => SsoPolicy::customerRoleNames(),
            'domainsText' => $c->exists ? implode("\n", $c->domainList()) : '',
            'mapText' => collect((array) $c->group_role_map)->map(fn ($r) => $r['group_id'].' = '.$r['role'])->implode("\n"),
        ];
    }

    private function auditValues(SamlConnection $c): array
    {
        return $c->only([
            'name', 'type', 'client_id', 'enabled', 'entra_tenant_id', 'idp_entity_id', 'idp_sso_url',
            'default_role', 'jit_provisioning', 'skip_local_mfa', 'group_role_map',
        ]) + ['domains' => $c->domainList(), 'idp_x509_cert_set' => (bool) $c->idp_x509_cert, 'idp_x509_cert_2_set' => (bool) $c->idp_x509_cert_2];
    }
}
