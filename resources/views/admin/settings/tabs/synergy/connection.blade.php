@php $s = $settings['synergy'] ?? []; @endphp
<x-settings.form-card area="synergy" tab="connection" title="Synergy Wholesale API" description="Used for domain sync and for Let's Encrypt DNS-01 certificates." save="Save credentials">
    <div class="dd-set-form">
        <x-settings.input label="Reseller ID" name="synergy[reseller_id]" :value="$s['reseller_id'] ?? ''" placeholder="Reseller ID" />
        <x-settings.input label="API key" name="synergy[api_key]" :value="$s['api_key'] ?? ''" :secret="true" placeholder="Set or update API key" help="Stored securely and never shown. Type a new key to replace it." />
        <x-settings.input label="WSDL path or URL" name="synergy[wsdl_path]" :value="$s['wsdl_path'] ?? ''" placeholder="WSDL path or URL" :wide="true" />
    </div>
</x-settings.form-card>
