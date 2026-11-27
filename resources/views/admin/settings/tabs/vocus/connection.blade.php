@php $v = $settings['vocus'] ?? []; $problem = \App\Services\Vocus\VocusWsmClient::configurationProblem(); @endphp
@if ($problem)
    <x-ui.alert variant="warning" title="Vocus is not ready">{{ $problem }}</x-ui.alert>
@endif
<x-settings.form-card area="vocus" tab="connection" title="Vocus WSM credentials" description="Access key and client certificate issued by Vocus during onboarding." save="Save connection" :multipart="true">
    <div class="dd-set-form">
        <x-settings.input label="Access key" name="vocus[access_key]" :value="$v['access_key'] ?? ''" placeholder="Provided by Vocus" help="Leave as ******** to keep the stored key." />
        <x-settings.input label="Alias key (optional)" name="vocus[alias_key]" :value="$v['alias_key'] ?? ''" placeholder="Optional" help="Leave as ******** to keep it." />
        <x-settings.input label="SOAP endpoint URL" name="vocus[wsdl_url]" type="url" :value="$v['wsdl_url'] ?? 'https://wsm.webservice.m2.com.au/WholesaleServiceManagement'" help="Production only; sandbox endpoints follow the environment on the Sandbox tab. Must be https." :wide="true" />
        <x-settings.input label="Certificate login URL" name="vocus[login_url]" type="url" :value="$v['login_url'] ?? 'https://wsm.webservice.m2.com.au:9443/login/'" :wide="true" />

        <div class="dd-set-field">
            <label for="vocus_cert" class="dd-set-label">Client certificate (.p12 / .pfx / .keystore / .jks)</label>
            @if (!empty($v['cert_path']))
                <small class="dd-set-help" style="color:var(--dd-status-success-fg)">Certificate on file. Upload a new file to replace it.</small>
            @endif
            <input id="vocus_cert" type="file" name="vocus_cert" accept=".p12,.pfx,.keystore,.jks" class="ui-input">
            <small class="dd-set-help">Vocus issues a new certificate yearly. File and password are checked on upload; Java JKS files must be converted to PKCS#12 first.</small>
            @error('vocus_cert')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
        </div>
        <x-settings.input label="Certificate password" name="vocus[cert_password]" :value="$v['cert_password'] ?? ''" :secret="true" placeholder="Set or update" help="Stored securely and never shown." />
    </div>
</x-settings.form-card>
