<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SamlConnection;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Saml\ConnectionValidator;
use App\Services\Saml\MetadataImporter;
use App\Services\Saml\SamlException;
use App\Services\Saml\SamlService;
use App\Services\Auth\LoginPolicy;
use App\Services\Microsoft\MicrosoftSettings;
use App\Services\Saml\SsoPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Administrator-only (route group `role:Administrator`). */
class SamlConnectionsController extends Controller
{
    public function __construct(private ConnectionValidator $validator, private MetadataImporter $importer, private SamlService $saml)
    {
    }

    public function index()
    {
        $connections = SamlConnection::query()->with('domains')->withCount('users')->orderBy('name')->get();

        $ms = MicrosoftSettings::all();
        $ms['client_secret'] = $ms['client_secret'] !== '' ? \App\Support\Redactor::FORM_MASK : '';

        return view('admin.sso.index', [
            'connections' => $connections,
            'policy' => LoginPolicy::settings(),
            'ms' => $ms,
            'msRedirectUri' => MicrosoftSettings::redirectUri(),
            'passwordUsers' => User::query()->where('password_login_allowed', true)->orderBy('name')->get(['id', 'name', 'email', 'is_active']),
        ]);
    }

    public function create()
    {
        $connection = new SamlConnection([
            'type' => SamlConnection::TYPE_STAFF,
            'jit_provisioning' => true,
            // Secure default: keep DomainDash's own MFA unless explicitly told to trust Entra.
            'skip_local_mfa' => false,
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

        if ($connection->enabled && ! $data['enabled']) {
            $this->guardLastConnection($connection);
        }

        $old = $this->auditValues($connection);
        DB::transaction(function () use ($data, $connection) {
            $connection->update(collect($data)->except(['domains', 'type'])->all());
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
        if ($connection->enabled) {
            $this->guardLastConnection($connection);
        }

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

    /** Staff "Microsoft only" needs a working SAML connection: the last enabled one cannot be switched off or deleted. */
    private function guardLastConnection(SamlConnection $connection): void
    {
        if (LoginPolicy::staffMode() === LoginPolicy::MICROSOFT_ONLY
            && ! SamlConnection::query()->where('enabled', true)->whereKeyNot($connection->id)->exists()) {
            throw ValidationException::withMessages(['enabled' => 'Password sign-in is closed for staff, so the last enabled SAML connection cannot be disabled or deleted. Reopen password sign-in under Login method policy first.']);
        }
    }

    // ---------------------------------------------------------------------------------------------------

    private function prepare(Request $request, ?SamlConnection $existing): array
    {
        $in = $request->all();
        $in['type'] = SamlConnection::TYPE_STAFF;

        // Dynamic mapping form: one box of Entra group object IDs per staff role.
        if (is_array($request->input('group_map'))) {
            $rows = [];
            foreach ($request->input('group_map') as $role => $ids) {
                foreach (preg_split('/[\s,;]+/', (string) $ids, -1, PREG_SPLIT_NO_EMPTY) as $gid) {
                    $rows[] = ['group_id' => $gid, 'role' => (string) $role];
                }
            }
            $in['group_role_map'] = $rows;
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
            // Staff roles only, read from the roles table every time (custom roles appear automatically).
            'roles' => SsoPolicy::staffRoleNames(),
            'domainsText' => $c->exists ? implode("\n", $c->domainList()) : '',
            'groupsByRole' => collect((array) $c->group_role_map)->groupBy('role')->map(fn ($rows) => $rows->pluck('group_id')->implode("\n"))->all(),
        ];
    }

    private function auditValues(SamlConnection $c): array
    {
        return $c->only([
            'name', 'type', 'enabled', 'entra_tenant_id', 'idp_entity_id', 'idp_sso_url',
            'default_role', 'jit_provisioning', 'skip_local_mfa', 'group_role_map',
        ]) + ['domains' => $c->domainList(), 'idp_x509_cert_set' => (bool) $c->idp_x509_cert, 'idp_x509_cert_2_set' => (bool) $c->idp_x509_cert_2];
    }
}
