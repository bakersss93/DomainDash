@php $s = $settings['ip2whois'] ?? []; @endphp
<x-settings.form-card area="ip2whois" tab="connection" title="IP2WHOIS API" description="WHOIS fallback for domains the registrar API cannot describe." save="Save API key">
    <div class="dd-set-form dd-set-form--one">
        <x-settings.input label="API key" name="ip2whois[api_key]" :value="$s['api_key'] ?? ''" :secret="true" placeholder="Enter IP2WHOIS API key" help="Get your key from IP2Location.io under IP2WHOIS." />
    </div>
    <x-slot name="extra">
        <button type="button" class="ui-btn ui-btn--secondary" onclick="openIp2whoisModal()">Sync WHOIS for selected domains</button>
    </x-slot>
</x-settings.form-card>
