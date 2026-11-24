@php
    $v = $settings['vocus'] ?? [];
    $env = $v['environment'] ?? config('services.vocus.environment', 'production');
    $pin = (string) ($v['sandbox_pin_ip'] ?? '1');
@endphp
<x-settings.form-card area="vocus" tab="sandbox" title="Environment" description="Switching starts a new Vocus session. Sandbox access is granted by Vocus." save="Save environment" :multipart="true">
    <div class="dd-set-form">
        <x-settings.select label="Environment" name="vocus[environment]" :options="['production' => 'Production', 'sandbox' => 'Sandbox (testing)']" :value="$env === 'sandbox' ? 'sandbox' : 'production'" />
        <x-settings.input label="Sandbox IP address" name="vocus[sandbox_ip]" :value="$v['sandbox_ip'] ?? config('services.vocus.sandbox.ip', '203.23.116.113')" placeholder="203.23.116.113" />
        <div class="dd-set-field dd-set-field--wide">
            <x-settings.check label="Connect to this IP in sandbox mode (no hosts-file entry needed)" name="vocus[sandbox_pin_ip]" :checked="$pin !== '0'" />
        </div>
        <div class="dd-set-field dd-set-field--wide">
            <label for="vocus_ca" class="dd-set-label">Sandbox CA certificate (.pem / .crt)</label>
            @if (!empty($v['ca_bundle_path']))
                <label class="dd-set-check"><input type="checkbox" name="vocus_ca_remove" value="1"> <span>CA certificate on file. Tick to remove it, or upload a new file to replace it.</span></label>
            @endif
            <input id="vocus_ca" type="file" name="vocus_ca" accept=".pem,.crt,.cer" class="ui-input">
            <small class="dd-set-help">The sandbox uses a self-signed certificate; upload its CA so the connection can be verified.</small>
            @error('vocus_ca')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
        </div>
    </div>
</x-settings.form-card>
