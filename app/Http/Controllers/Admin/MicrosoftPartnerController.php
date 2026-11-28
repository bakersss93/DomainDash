<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PartnerCustomerTenant;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\Microsoft\Partner\PartnerGraphClient;
use App\Services\Microsoft\Partner\PartnerGraphException;
use App\Services\Microsoft\Partner\PartnerSettings;
use App\Services\Microsoft\Partner\PartnerTenantMapper;
use App\Services\Sync\SyncDispatcher;
use App\Support\Redactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Settings > Microsoft Partner. Routes sit inside the Administrator-only admin group (see routes/web.php). */
class MicrosoftPartnerController extends Controller
{
    private function back(string $tab, string $key, string $message): RedirectResponse
    {
        return redirect()->route('admin.settings.area', ['area' => 'microsoft-partner', 'tab' => $tab])->with($key, $message);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'partner_tenant_id' => ['required', 'regex:'.PartnerSettings::GUID],
            'client_id' => ['required', 'regex:'.PartnerSettings::GUID],
            'client_secret' => ['nullable', 'string', 'max:255'],
            'source' => ['required', Rule::in(PartnerSettings::SOURCES)],
        ], [
            'partner_tenant_id.regex' => 'The partner tenant ID must be a GUID such as 00000000-0000-0000-0000-000000000000.',
            'client_id.regex' => 'The application (client) ID must be a GUID such as 00000000-0000-0000-0000-000000000000.',
        ]);

        $current = PartnerSettings::all();
        $secret = (string) ($data['client_secret'] ?? '');
        // Blank or the form placeholder keeps the stored secret.
        $keep = $secret === '' || $secret === Redactor::FORM_MASK;

        $new = [
            'partner_tenant_id' => strtolower(trim($data['partner_tenant_id'])),
            'client_id' => strtolower(trim($data['client_id'])),
            'client_secret' => $keep ? (string) $current['client_secret'] : $secret,
            'source' => $data['source'],
        ];
        Setting::put(PartnerSettings::KEY, $new);
        app(PartnerGraphClient::class)->forgetToken();

        AuditLogger::logSystem('partner.settings.updated', 'Microsoft Partner connection settings updated.', ['function' => 'partner-settings', 'automated' => false], [
            'old_values' => ['partner_tenant_id' => $current['partner_tenant_id'], 'client_id' => $current['client_id'], 'source' => $current['source']],
            'new_values' => ['partner_tenant_id' => $new['partner_tenant_id'], 'client_id' => $new['client_id'], 'source' => $new['source'], 'client_secret_changed' => ! $keep],
        ]);

        return $this->back('connection', 'success', 'Microsoft Partner connection saved.');
    }

    public function test(): RedirectResponse
    {
        if (! PartnerSettings::configured()) {
            return $this->back('connection', 'partner_error', 'Save the partner tenant ID, application ID and client secret first.');
        }

        try {
            app(PartnerGraphClient::class)->test();
        } catch (PartnerGraphException $e) {
            AuditLogger::logSystem('partner.sync.test_failed', 'Microsoft Partner connection test failed.', ['function' => 'partner-test', 'automated' => false], [
                'new_values' => ['reason' => $e->reason, 'error' => $e->getMessage()],
            ]);

            return $this->back('connection', 'partner_error', 'Connection test failed: '.$e->getMessage());
        }

        AuditLogger::logSystem('partner.sync.test_ok', 'Microsoft Partner connection test succeeded.', ['function' => 'partner-test', 'automated' => false]);

        return $this->back('connection', 'success', 'Connection OK: a token was issued and the customer list is readable.');
    }

    public function sync(Request $request, SyncDispatcher $dispatcher): RedirectResponse
    {
        if (! PartnerSettings::configured()) {
            return $this->back('connection', 'partner_error', 'Configure the Microsoft Partner connection before syncing.');
        }

        $dispatcher->refreshPartnerTenants($request->user()?->id);

        return $this->back('customers', 'success', 'Sync queued. The customer list updates when the queue worker finishes (reload to see it).');
    }

    public function map(Request $request, PartnerCustomerTenant $tenant, PartnerTenantMapper $mapper): RedirectResponse
    {
        $request->validate(['client' => ['required', 'max:300']]);

        $client = $this->resolveClient((string) $request->input('client'));
        if ($client === null) {
            return $this->back('customers', 'partner_error', 'Choose a client from the list.');
        }

        $mapper->map($tenant, $client);

        return $this->back('customers', 'success', "{$tenant->display_name} is mapped to {$client->business_name}.");
    }

    public function clear(PartnerCustomerTenant $tenant, PartnerTenantMapper $mapper): RedirectResponse
    {
        $mapper->clear($tenant);

        return $this->back('customers', 'success', 'Mapping removed.');
    }

    /** Accepts a client id, or the datalist text "Business name (#id)". */
    private function resolveClient(string $input): ?Client
    {
        $input = trim($input);
        if (preg_match('/#(\d+)\)?$/', $input, $m)) {
            return Client::find((int) $m[1]);
        }
        if (ctype_digit($input)) {
            return Client::find((int) $input);
        }

        $matches = Client::query()->where('business_name', $input)->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
