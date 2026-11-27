@php $i = $settings['itglue'] ?? []; @endphp
<x-settings.form-card area="itglue" tab="connection" title="IT Glue API" description="Save here first: the other tabs load lists with the saved credentials." save="Save connection">
    <div class="dd-set-form">
        <x-settings.input label="Base URL" name="itglue[base_url]" :value="$i['base_url'] ?? ''" placeholder="https://api.itglue.com" help="Blank uses the US endpoint." />
        <x-settings.input label="API key" name="itglue[api_key]" :value="$i['api_key'] ?? ''" :secret="true" placeholder="Set or update" help="Stored securely and never shown." />
    </div>
</x-settings.form-card>
