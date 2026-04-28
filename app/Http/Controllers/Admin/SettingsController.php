<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Setting;
use Illuminate\Support\Facades\Mail;
use App\Support\MailSettings;
use App\Services\AuditLogger;
use App\Services\Halo\HaloPsaClient;

class SettingsController extends Controller
{
    public function index()
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
            'backup'   => Setting::get('backup', ['host'=>'','port'=>22,'username'=>'','password'=>'','path'=>'/','retention'=>7,'time'=>'02:00']),
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
        return view('admin.settings.index', compact('settings'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'branding'      => 'array',
            'smtp'          => 'array',
            'synergy'       => 'array',
            'halo'          => 'array',
            'halo.base_url' => 'nullable|string|max:255',
            'halo.auth_server' => 'nullable|string|max:255',
            'halo.tenant' => 'nullable|string|max:120',
            'halo.client_id' => 'nullable|string|max:120',
            'halo.api_key' => 'nullable|string|max:255',
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
            'ip2whois'      => 'array',
            'vocus'                   => 'array',
            'vocus.access_key'        => 'nullable|string|max:64',
            'vocus.alias_key'         => 'nullable|string|max:64',
            'vocus.wsdl_url'          => 'nullable|url|max:255',
            'vocus.login_url'         => 'nullable|url|max:255',
            'vocus.cert_password'     => 'nullable|string|max:255',
            'vocus_cert'              => 'nullable|file|mimes:p12,pfx|max:512',
            'sync_schedule' => 'array',
            'sync_schedule.*' => 'array',
            'sync_schedule.*.enabled' => 'nullable|boolean',
            'sync_schedule.*.frequency' => 'nullable|in:hourly,daily,weekly',
            'sync_schedule.*.time' => 'nullable|date_format:H:i',
            'backup'        => 'array',
            'notifications' => 'array',
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
            $file->storeAs('vocus', 'client.p12');
            $data['vocus'] = array_merge($data['vocus'] ?? Setting::get('vocus', []), [
                'cert_path' => 'vocus/client.p12',
            ]);
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
            if (in_array($key, ['branding_logo', 'vocus_cert'])) {
                continue;
            }

            $currentValue = Setting::get($key, null);
            $diff = $this->extractChangedValues($currentValue, $value);
            if ($diff === null) {
                continue;
            }

            Setting::put($key, $value);
            $oldValues[$key] = $diff['old'];
            $newValues[$key] = $diff['new'];
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

        return back()->with('status', empty($newValues) ? 'No setting changes detected.' : 'Settings saved.');
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
