@php $a = $settings['audit'] ?? []; @endphp
<x-settings.form-card area="mfa" tab="audit" title="Audit log retention" description="Entries older than this are pruned by the nightly job. Integration log levels and per-category retention are under Admin > Audit Logs." save="Save retention">
    <div class="dd-set-form dd-set-form--one">
        <x-settings.input label="Keep audit entries for (days)" name="audit[retention_days]" type="number" :value="$a['retention_days'] ?? 90" min="1" max="3650" :help="'Sign-in, MFA, impersonation, API key, settings and secret-reveal events are always kept at least ' . config('monitoring.security_retention_floor_days', 365) . ' days.'" />
        <x-settings.input label="Keep security events (days)" name="audit[retention_days_security]" type="number" :value="$a['retention_days_security'] ?? config('monitoring.security_retention_floor_days', 365)" :min="config('monitoring.security_retention_floor_days', 365)" max="3650" />
    </div>
</x-settings.form-card>
