@php $h = $settings['halo'] ?? []; @endphp
<x-settings.form-card area="halopsa" tab="connection" title="HaloPSA API" description="From Halo > Configuration > Integrations > Halo API. Save here first: the other tabs load lists with the saved credentials." save="Save connection">
    <div class="dd-set-form">
        <x-settings.input label="Resource server URL" name="halo[base_url]" :value="$h['base_url'] ?? ''" placeholder="https://yourtenant.halopsa.com/api" :wide="true" />
        <x-settings.input label="Authorisation server URL" name="halo[auth_server]" :value="$h['auth_server'] ?? ''" placeholder="https://auth.halopsa.com/auth" help="Optional. Blank derives it from the resource server." />
        <x-settings.input label="Tenant" name="halo[tenant]" :value="$h['tenant'] ?? ''" placeholder="yourtenant" help="Hosted HaloPSA cloud only." />
        <x-settings.input label="Client ID" name="halo[client_id]" :value="$h['client_id'] ?? ''" />
        <x-settings.input label="API key (client secret)" name="halo[api_key]" :value="$h['api_key'] ?? ''" :secret="true" placeholder="Set or update" help="Stored securely and never shown." />
    </div>
</x-settings.form-card>
