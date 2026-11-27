@php $m = $settings['mfa'] ?? []; @endphp
<x-settings.form-card area="mfa" tab="mfa" title="Multi-factor authentication" description="Defaults for sign-in challenges." save="Save MFA settings">
    <div class="dd-set-form">
        <x-settings.input label="Remember a browser for (days)" name="mfa[persistence_days]" type="number" :value="$m['persistence_days'] ?? 30" min="1" max="365" help="How long a remembered browser skips the MFA prompt." />
        <x-settings.select label="Primary method" name="mfa[default_method]" :options="['authenticator_app'=>'Authenticator app (TOTP)', 'email_otp'=>'Email one-time passcode']" :value="$m['default_method'] ?? 'authenticator_app'" />
        <x-settings.input label="Issuer label" name="mfa[issuer]" :value="$m['issuer'] ?? config('app.name')" help="Shown in the authenticator app." />
        <div class="dd-set-field">
            <x-settings.check label="Allow recovery codes" name="mfa[allow_recovery_codes]" :checked="!empty($m['allow_recovery_codes'])" />
            <x-settings.check label="Allow remembered devices" name="mfa[remember_browser]" :checked="!empty($m['remember_browser'])" />
        </div>
    </div>
</x-settings.form-card>
