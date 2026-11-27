<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Setting;
use Illuminate\Support\Facades\Mail;
use App\Support\MailSettings;
use App\Services\AuditLogger;
use App\Services\Backup\BackupService;
use App\Services\Halo\HaloPsaClient;
use App\Support\Settings\SettingsAreas;

class SettingsController extends Controller
{
    /** Settings home: categorised tiles linking to one page per area. */
    public function index(Request $request)
    {
        // Old deep links (?section=halo) land on the matching area page.
        $legacy = ['halo' => 'halopsa', 'smtp' => 'email', 'sync-scheduler' => 'sync', 'branding' => 'branding', 'itglue' => 'itglue',
            'vocus' => 'vocus', 'synergy' => 'synergy', 'ip2whois' => 'ip2whois', 'mfa' => 'mfa'];
        $section = (string) $request->query('section', '');
        if (isset($legacy[$section])) {
            return redirect()->route('admin.settings.area', $legacy[$section]);
        }

        return view('admin.settings.home', [
            'categories' => SettingsAreas::categories(),
            'areas' => SettingsAreas::all(),
            'status' => $this->areaStatus(),
            'notes' => $this->areaNotes(),
        ]);
    }

    /** One settings area with its tabs: /admin/settings/{area}[#tab or ?tab=]. */
    public function area(Request $request, string $area)
    {
        $def = SettingsAreas::get($area);
        abort_if($def === null, 404);

        $tab = (string) $request->query('tab', '');

        return view('admin.settings.area', [
            'area' => $area,
            'def' => $def,
            'initialTab' => array_key_exists($tab, $def['tabs']) ? $tab : array_key_first($def['tabs']),
            'settings' => $this->settingsForForm(),
        ]);
    }

    /** Small "configured / not configured" hint per tile (never reads secrets back). */
    private function areaStatus(): array
    {
        $halo = (array) Setting::get('halo', []);
        $itg = (array) Setting::get('itglue', []);
        $syn = (array) Setting::get('synergy', []);
        $vocus = (array) Setting::get('vocus', []);
        $ip = (array) Setting::get('ip2whois', []);
        $smtp = (array) Setting::get('smtp', []);
        $backup = (array) Setting::get('backup', []);

        return [
            'halopsa' => ! empty($halo['base_url']) && ! empty($halo['client_id']),
            'itglue' => ! empty($itg['api_key']),
            'synergy' => ! empty($syn['api_key']),
            'vocus' => ! empty($vocus['access_key']),
            'ip2whois' => ! empty($ip['api_key']),
            'email' => ! empty($smtp['host']),
            // "Configured" is not enough for backups: it must have actually succeeded at least once.
            'backups' => ! empty($backup['host']) && BackupService::status()['last_success_at'] !== null,
        ];
    }

    /** Extra one-line hint per tile (currently: last successful backup). */
    private function areaNotes(): array
    {
        $b = BackupService::status();
        $text = $b['last_success_at']
            ? 'Last successful backup: ' . \Illuminate\Support\Carbon::parse($b['last_success_at'])->format('Y-m-d H:i') . ' UTC'
            : (BackupService::isConfigured() ? 'No successful backup yet' : null);
        if ($b['last_failure_at'] && $b['last_error']) {
            $text = trim(($text ? $text . '. ' : '') . 'Last attempt failed: ' . $b['last_error']);
        }

        return ['backups' => $text];
    }

    /** Every setting group the forms read, with stored secrets swapped for the "********" placeholder. */
    public function settingsForForm(): array
    {
        $settings = [
            'branding' => Setting::get('branding', [
                'primary'          => '#1f2937',
                'accent'           => '#06b6d4',
                'text'             => '#111827',
                'bg'               => '#ffffff',
                'button_text'      => '#ffffff',
                'primary_dark'     => '#0b1220',
                'accent_dark'      => '#22d3ee',
                'text_dark'        => '#e2e8f0',
                'bg_dark'          => '#0f172a',
                'button_text_dark' => '#0f172a',
            ]),
            'smtp'     => Setting::get('smtp', []),
            'synergy'  => Setting::get('synergy', []),
            'halo'     => array_merge([
                'ticket_type_mappings' => [],
                'status_mappings' => [],
            ], Setting::get('halo', [])),
            'itglue'   => Setting::get('itglue', []),
            'ip2whois' => Setting::get('ip2whois', []),
            'vocus'    => Setting::get('vocus', []),
            'sync_schedule' => Setting::get('sync_schedule', [
                'sync_domains' => ['enabled' => false, 'frequency' => 'daily', 'time' => '01:30'],
                'sync_hosting_services' => ['enabled' => false, 'frequency' => 'daily', 'time' => '02:00'],
                'sync_halo_assets' => ['enabled' => false, 'frequency' => 'daily', 'time' => '02:30'],
                'sync_itglue' => ['enabled' => false, 'frequency' => 'daily', 'time' => '03:00'],
            ]),
            'backup'   => array_merge(['host'=>'','port'=>22,'username'=>'','password'=>'','path'=>'/','retention'=>7,'time'=>'02:00','host_fingerprint'=>'','encryption_password'=>''], (array) Setting::get('backup', [])),
            'notifications' => Setting::get('notifications', ['disk_threshold_percent'=>90]),
            'mfa' => Setting::get('mfa', [
                'persistence_days' => 30,
                'default_method' => 'authenticator_app',
                'allow_recovery_codes' => true,
                'remember_browser' => true,
                'issuer' => config('app.name', 'DomainDash'),
            ]),
            'audit' => Setting::get('audit', [
                'retention_days' => 90,
            ]),
        ];

        // Never echo stored secrets into the page: show the placeholder instead.
        return \App\Support\Redactor::maskForForm($settings);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'branding'      => 'array',
            'smtp'          => 'array',
            'synergy'       => 'array',
            'halo'          => 'array',
            'halo.base_url' => ['nullable', 'string', 'max:255', new \App\Rules\SafeExternalUrl()],
            'halo.auth_server' => ['nullable', 'string', 'max:255', new \App\Rules\SafeExternalUrl()],
            'halo.tenant' => 'nullable|string|max:120',
            'halo.client_id' => 'nullable|string|max:120',
            'halo.api_key' => 'nullable|string|max:255',
            'halo.asset_field_ids' => 'nullable|array',
            // 0 = "Don't sync" this data point; blank = built-in default id.
            'halo.asset_field_ids.domain' => 'nullable|integer|min:0',
            'halo.asset_field_ids.expiry' => 'nullable|integer|min:0',
            'halo.asset_field_ids.nameservers' => 'nullable|integer|min:0',
            'halo.asset_field_ids.whois' => 'nullable|integer|min:0',
            'halo.billing' => 'nullable|array',
            'halo.billing.sync_subscriptions' => 'nullable|boolean',
            'halo.billing.sync_lines' => 'nullable|boolean',
            'halo.billing.strategy' => 'nullable|in:contract,client',
            'halo.billing.contract_type_id' => 'nullable|integer|min:1',
            'halo.billing.contract_ref' => 'nullable|string|max:120',
            'halo.billing.contract_subtype_id' => 'nullable|integer|min:1',
            'halo.billing.type_lookup_id' => 'nullable|integer|min:1',
            'halo.billing.subtype_lookup_id' => 'nullable|integer|min:1',
            'halo.billing.line_description' => 'nullable|string|max:255',
            'halo.billing.line_long_description' => 'nullable|string|max:2000',
            'halo.asset_type_id' => 'nullable|integer|min:1',
            'halo.asset_type_name' => 'nullable|string|max:180',
            'halo.asset_field_names' => 'nullable|array',
            'halo.asset_field_names.domain' => 'nullable|string|max:180',
            'halo.asset_field_names.expiry' => 'nullable|string|max:180',
            'halo.asset_field_names.nameservers' => 'nullable|string|max:180',
            'halo.asset_field_names.whois' => 'nullable|string|max:180',
            'halo.asset_key_fields' => 'nullable|array',
            'halo.asset_key_fields.1' => 'nullable|in:domain,expiry,nameservers,whois',
            'halo.asset_key_fields.2' => 'nullable|in:domain,expiry,nameservers,whois',
            'halo.asset_key_fields.3' => 'nullable|in:domain,expiry,nameservers,whois',
            'halo.ticket_type_mappings' => 'nullable|array',
            'halo.ticket_type_mappings.*' => 'nullable|array',
            'halo.ticket_type_mappings.*.service_category' => 'nullable|string|max:120',
            'halo.ticket_type_mappings.*.ticket_type' => 'nullable|in:Support/Issue,Service Request',
            'halo.ticket_type_mappings.*.halo_ticket_type_id' => 'nullable|integer|min:1',
            'halo.ticket_type_mappings.*.halo_ticket_type_name' => 'nullable|string|max:180',
            'halo.status_mappings' => 'nullable|array',
            'halo.status_mappings.*' => 'nullable|array',
            'halo.status_mappings.*.domaindash_status' => 'nullable|string|max:120',
            'halo.status_mappings.*.halo_status_id' => 'nullable|integer|min:1',
            'halo.status_mappings.*.halo_status_name' => 'nullable|string|max:180',
            'itglue'        => 'array',
            'itglue.base_url' => ['nullable', 'string', 'max:255', new \App\Rules\SafeExternalUrl()],
            'itglue.api_key' => 'nullable|string|max:255',
            'itglue.flexible_asset_type_id' => 'nullable|integer|min:1',
            'itglue.flexible_asset_type_name' => 'nullable|string|max:180',
            'itglue.flexible_asset_traits' => 'nullable|array',
            'itglue.flexible_asset_traits.*' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9_-]+$/'],
            'ip2whois'      => 'array',
            'vocus'                   => 'array',
            'vocus.access_key'        => 'nullable|string|max:64',
            'vocus.alias_key'         => 'nullable|string|max:64',
            'vocus.wsdl_url'          => ['nullable', 'url:https', 'max:255', new \App\Rules\SafeExternalUrl()],
            'vocus.login_url'         => ['nullable', 'url:https', 'max:255', new \App\Rules\SafeExternalUrl()],
            'vocus.cert_password'     => 'nullable|string|max:255',
            'vocus.environment'       => 'nullable|in:production,sandbox',
            'vocus.sandbox_ip'        => 'nullable|ip',
            'vocus.sandbox_pin_ip'    => 'nullable|in:0,1',
            'vocus.order_defaults'               => 'nullable|array',
            'vocus.order_defaults.auth_type'     => ['nullable', \Illuminate\Validation\Rule::in(\App\Services\Vocus\VocusOptions::AUTH_TYPES)],
            'vocus.order_defaults.ip_assignment' => ['nullable', \Illuminate\Validation\Rule::in(\App\Services\Vocus\VocusOptions::IP_ASSIGNMENTS)],
            'vocus.order_defaults.service_level' => ['nullable', \Illuminate\Validation\Rule::in(\App\Services\Vocus\VocusOptions::SERVICE_LEVELS)],
            'vocus.order_defaults.nbn_cpe'       => 'nullable|array:FTTP,HFC,FTTC',
            'vocus.order_defaults.nbn_cpe.*'     => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
            'vocus_ca'                => 'nullable|file|extensions:pem,crt,cer|max:256',
            'vocus_cert'              => 'nullable|file|extensions:p12,pfx,keystore,jks|max:512',
            'sync_schedule' => 'array',
            'sync_schedule.*' => 'array',
            'sync_schedule.*.enabled' => 'nullable|boolean',
            'sync_schedule.*.frequency' => 'nullable|in:hourly,daily,weekly',
            'sync_schedule.*.time' => 'nullable|date_format:H:i',
            'backup'        => 'array',
            'backup.host'   => 'nullable|string|max:255',
            'backup.port'   => 'nullable|integer|min:1|max:65535',
            'backup.username' => 'nullable|string|max:255',
            'backup.password' => 'nullable|string|max:255',
            'backup.path'   => 'nullable|string|max:255',
            'backup.retention' => 'nullable|integer|min:1|max:365',
            'backup.time'   => 'nullable|date_format:H:i',
            'notifications' => 'array',
            'notifications.disk_threshold_percent' => 'nullable|integer|min:1|max:100',
            'mfa'           => 'array',
            'mfa.persistence_days' => 'nullable|integer|min:1|max:365',
            'mfa.default_method' => 'nullable|in:authenticator_app,email_otp',
            'mfa.allow_recovery_codes' => 'nullable|boolean',
            'mfa.remember_browser' => 'nullable|boolean',
            'mfa.issuer' => 'nullable|string|max:120',
            'audit' => 'array',
            'audit.retention_days' => 'nullable|integer|min:1|max:3650',
            'branding_logo' => 'nullable|file|image|max:2048', // up to 2MB
        ]);

        /**
         * Partial saves: each settings page posts only its own fields, so every posted group is merged into what is
         * stored (recursively for keyed sub-arrays; lists such as the ticket type mappings are replaced as a whole).
         * A tab that posts one key never wipes the others.
         */
        foreach (['branding', 'smtp', 'synergy', 'halo', 'itglue', 'ip2whois', 'sync_schedule', 'backup', 'notifications', 'mfa', 'audit', 'vocus'] as $group) {
            if (isset($data[$group]) && is_array($data[$group])) {
                $stored = Setting::get($group, []);
                $data[$group] = $this->mergeSettings(is_array($stored) ? $stored : [], $data[$group]);
            }
        }

        /**
         * Halo API key sentinel:
         * If the form posts "********" we keep the existing secret.
         */
        if (isset($data['halo']) && is_array($data['halo'])) {
            $currentHalo = Setting::get('halo', []);
            if (!is_array($currentHalo)) {
                $currentHalo = [];
            }

            // Merge with existing settings so missing posted keys do not erase
            // previously saved Halo credentials.
            $data['halo'] = array_merge($currentHalo, $data['halo']);

            if (array_key_exists('api_key', $data['halo']) && $data['halo']['api_key'] === '********') {
                if (isset($currentHalo['api_key'])) {
                    $data['halo']['api_key'] = $currentHalo['api_key'];
                } else {
                    unset($data['halo']['api_key']);
                }
            }
        }

        /**
         * Synergy API key sentinel:
         * Same behaviour as Halo – "********" means keep existing key.
         */
        if (isset($data['synergy']) && is_array($data['synergy'])) {
            if (array_key_exists('api_key', $data['synergy']) && $data['synergy']['api_key'] === '********') {
                $currentSynergy = Setting::get('synergy', []);
                if (isset($currentSynergy['api_key'])) {
                    $data['synergy']['api_key'] = $currentSynergy['api_key'];
                } else {
                    unset($data['synergy']['api_key']);
                }
            }
        }

        // Vocus: sentinel for cert_password and handle certificate upload.
        if (isset($data['vocus']) && is_array($data['vocus'])) {
            $currentVocus = Setting::get('vocus', []);
            if (!is_array($currentVocus)) {
                $currentVocus = [];
            }

            $data['vocus'] = array_merge($currentVocus, $data['vocus']);

            if (array_key_exists('cert_password', $data['vocus']) && $data['vocus']['cert_password'] === '********') {
                $data['vocus']['cert_password'] = $currentVocus['cert_password'] ?? null;
            }
        }

        if ($request->hasFile('vocus_cert')) {
            $file = $request->file('vocus_cert');
            $ext = strtolower($file->getClientOriginalExtension() ?: 'p12');
            $filename = 'client.' . $ext;
            $currentVocus = (array) Setting::get('vocus', []);
            $password = (string) (($data['vocus']['cert_password'] ?? null) ?: ($currentVocus['cert_password'] ?? ''));

            // Reject a certificate that cannot work before it replaces the working one.
            if (\App\Services\Vocus\VocusWsmClient::isJavaKeystore($file->getRealPath())) {
                throw \Illuminate\Validation\ValidationException::withMessages(['vocus_cert' => \App\Services\Vocus\VocusWsmClient::JKS_MESSAGE]);
            }
            if (function_exists('openssl_pkcs12_read')) {
                $certs = [];
                if (! @openssl_pkcs12_read((string) file_get_contents($file->getRealPath()), $certs, $password)) {
                    while (openssl_error_string() !== false) {
                        // drain the OpenSSL error queue (never shown: it can name internals)
                    }
                    throw \Illuminate\Validation\ValidationException::withMessages(['vocus_cert' =>
                        'The certificate could not be read. Check the certificate password. If the password is correct, the file may use legacy encryption (RC2/3DES) that OpenSSL 3 rejects: re-export it as a modern PKCS#12 file.']);
                }
            }

            $file->storeAs('vocus', $filename);

            // Remove the previous private key when the file name changes (client.p12 -> client.pfx).
            $previous = $currentVocus['cert_path'] ?? null;
            if ($previous && $previous !== 'vocus/' . $filename) {
                \Illuminate\Support\Facades\Storage::delete($previous);
            }

            $data['vocus'] = array_merge($data['vocus'] ?? Setting::get('vocus', []), [
                'cert_path' => 'vocus/' . $filename,
            ]);
        }

        // Vocus CA bundle (PEM) for the sandbox's self-signed certificate.
        if ($request->hasFile('vocus_ca')) {
            $pem = (string) file_get_contents($request->file('vocus_ca')->getRealPath());
            if (! str_contains($pem, '-----BEGIN CERTIFICATE-----') || (function_exists('openssl_x509_read') && @openssl_x509_read($pem) === false)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['vocus_ca' =>
                    'The CA file must be a PEM certificate (text starting with -----BEGIN CERTIFICATE-----).']);
            }
            \Illuminate\Support\Facades\Storage::put('vocus/ca.pem', $pem);
            $data['vocus'] = array_merge($data['vocus'] ?? Setting::get('vocus', []), ['ca_bundle_path' => 'vocus/ca.pem']);
        } elseif ($request->boolean('vocus_ca_remove')) {
            \Illuminate\Support\Facades\Storage::delete('vocus/ca.pem');
            $data['vocus'] = array_merge($data['vocus'] ?? Setting::get('vocus', []), ['ca_bundle_path' => null]);
        }

        // Handle logo upload if provided.
        if ($request->hasFile('branding_logo')) {
            $file = $request->file('branding_logo');
            $path = $file->store('logos', 'public'); // e.g. logos/abcd1234.png
            $data['branding'] = array_merge(
                Setting::get('branding', []),
                $data['branding'] ?? [],
                ['logo' => $path]
            );
        } elseif (isset($data['branding']) && is_array($data['branding'])) {
            $data['branding'] = array_merge(Setting::get('branding', []), $data['branding']);
        }

        $oldValues = [];
        $newValues = [];

        // Persist only values that actually changed and capture a precise audit diff.
        foreach ($data as $key => $value) {
            if (in_array($key, ['branding_logo', 'vocus_cert', 'vocus_ca', 'vocus_ca_remove'])) {
                continue;
            }

            $currentValue = Setting::get($key, null);
            // Keep stored secrets unless a genuinely new value was submitted.
            $value = \App\Support\Redactor::preserveSecrets($value, $currentValue);
            $diff = $this->extractChangedValues($currentValue, $value);
            if ($diff === null) {
                continue;
            }

            Setting::put($key, $value);
            // Audit diff never contains secret values.
            $oldValues[$key] = \App\Support\Redactor::redact($diff['old']);
            $newValues[$key] = \App\Support\Redactor::redact($diff['new']);
        }

        // Dedicated audit entry for asset type / field mapping changes (ids and names only, no secrets).
        $mappingKeys = ['asset_type_id', 'asset_type_name', 'asset_field_ids', 'asset_field_names', 'asset_key_fields'];
        $mappingOld = array_intersect_key((array) ($oldValues['halo'] ?? []), array_flip($mappingKeys));
        $mappingNew = array_intersect_key((array) ($newValues['halo'] ?? []), array_flip($mappingKeys));
        foreach (['flexible_asset_type_id', 'flexible_asset_type_name', 'flexible_asset_traits'] as $k) {
            if (isset($newValues['itglue'][$k]) || isset($oldValues['itglue'][$k])) {
                $mappingOld['itglue.' . $k] = $oldValues['itglue'][$k] ?? null;
                $mappingNew['itglue.' . $k] = $newValues['itglue'][$k] ?? null;
            }
        }
        if ($mappingNew !== [] || $mappingOld !== []) {
            AuditLogger::logSystem('integration.mapping.update', 'Asset type / field mapping changed.', [
                'service' => 'settings',
                'function' => 'integration-mapping-update',
            ], [
                'old_values' => $mappingOld,
                'new_values' => $mappingNew,
            ]);
        }

        if (!empty($newValues)) {
            AuditLogger::logSystem('settings.update', 'System settings updated.', [
                'service' => 'settings',
                'function' => 'settings-update',
                'changed_keys' => array_keys($newValues),
            ], [
                'old_values' => $oldValues,
                'new_values' => $newValues,
            ]);
        }

        $status = empty($newValues) ? 'No setting changes detected.' : 'Settings saved.';

        // Settings pages post a `_return` path (their own URL + #tab) so the person lands back on the tab they saved.
        $return = (string) $request->input('_return', '');
        if (preg_match('#^/admin/settings(/[a-z0-9-]+)?(\?[A-Za-z0-9=&_-]*)?(\#[a-z0-9-]+)?$#', $return)) {
            return redirect($return)->with('status', $status);
        }

        return back()->with('status', $status);
    }

    /**
     * Recursive merge for settings arrays: keyed arrays merge key by key, lists and scalars are replaced.
     */
    private function mergeSettings(array $stored, array $posted): array
    {
        foreach ($posted as $key => $value) {
            if (is_array($value) && isset($stored[$key]) && is_array($stored[$key])
                && ! array_is_list($value) && ! array_is_list($stored[$key])) {
                $stored[$key] = $this->mergeSettings($stored[$key], $value);
            } else {
                $stored[$key] = $value;
            }
        }

        return $stored;
    }

    public function haloTicketTypes()
    {
        try {
            $halo = app(HaloPsaClient::class);
            $types = $halo->listTicketTypes();
            $normalized = array_values(array_map(function (array $type): array {
                return [
                    'id' => (int) ($type['id'] ?? $type['Id'] ?? 0),
                    'name' => (string) ($type['name'] ?? $type['Name'] ?? ('Type #' . ($type['id'] ?? $type['Id'] ?? '0'))),
                ];
            }, $types));

            $normalized = array_values(array_filter($normalized, fn (array $type): bool => $type['id'] > 0));

            return response()->json([
                'types' => $normalized,
            ]);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'types' => [],
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    public function haloTicketStatuses()
    {
        try {
            $halo = app(HaloPsaClient::class);
            $statuses = $halo->listTicketStatuses();
            $normalized = array_values(array_map(function (array $status): array {
                return [
                    'id' => (int) ($status['id'] ?? $status['Id'] ?? 0),
                    'name' => (string) ($status['name'] ?? $status['Name'] ?? ('Status #' . ($status['id'] ?? $status['Id'] ?? '0'))),
                ];
            }, $statuses));

            $normalized = array_values(array_filter($normalized, fn (array $status): bool => $status['id'] > 0));

            return response()->json([
                'statuses' => $normalized,
            ]);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'statuses' => [],
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    private function extractChangedValues($oldValue, $newValue): ?array
    {
        if (is_array($oldValue) && is_array($newValue)) {
            $oldChanges = [];
            $newChanges = [];

            foreach (array_unique(array_merge(array_keys($oldValue), array_keys($newValue))) as $key) {
                $oldExists = array_key_exists($key, $oldValue);
                $newExists = array_key_exists($key, $newValue);
                $oldItem = $oldExists ? $oldValue[$key] : null;
                $newItem = $newExists ? $newValue[$key] : null;
                $childDiff = $this->extractChangedValues($oldItem, $newItem);

                if ($childDiff !== null) {
                    $oldChanges[$key] = $childDiff['old'];
                    $newChanges[$key] = $childDiff['new'];
                }
            }

            if (empty($oldChanges) && empty($newChanges)) {
                return null;
            }

            return [
                'old' => $oldChanges,
                'new' => $newChanges,
            ];
        }

        if ($oldValue == $newValue) {
            return null;
        }

        return [
            'old' => $oldValue,
            'new' => $newValue,
        ];
    }

    public function testSmtp(Request $request)
    {
        $request->validate(['to' => 'required|email']);

        // Load SMTP settings from database
        $smtp = Setting::get('smtp', []);

        // Validate that SMTP settings are configured
        if (! MailSettings::isConfigured($smtp)) {
            return back()->withErrors(['smtp' => 'SMTP settings are not fully configured. Please configure host, port, and from address.']);
        }

        MailSettings::apply($smtp);

        Mail::raw('This is a test email from DomainDash. If you received this, your SMTP configuration is working correctly!', function ($m) use ($request) {
            $m->to($request->to)
                ->subject('DomainDash SMTP Test');
        });

        return back()->with('status', 'Test email sent successfully to ' . $request->to);
    }
}
