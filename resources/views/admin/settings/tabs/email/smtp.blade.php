@php $s = $settings['smtp'] ?? []; @endphp
<div class="dd-set-split">
    <x-settings.form-card area="email" tab="smtp" title="SMTP server" description="Used for notifications, invitations and password resets." save="Save SMTP settings">
        <div class="dd-set-form">
            <x-settings.input label="Host" name="smtp[host]" :value="$s['host'] ?? ''" placeholder="smtp.example.com" />
            <x-settings.input label="Port" name="smtp[port]" type="number" :value="$s['port'] ?? 587" placeholder="587" help="587 (TLS), 465 (SSL) or 25 (unencrypted)." />
            <x-settings.select label="Encryption" name="smtp[encryption]" :options="[''=>'None', 'tls'=>'TLS', 'ssl'=>'SSL']" :value="$s['encryption'] ?? ''" />
            <x-settings.input label="Username" name="smtp[username]" :value="$s['username'] ?? ''" placeholder="If required" />
            <x-settings.input label="Password" name="smtp[password]" :value="$s['password'] ?? ''" :secret="true" placeholder="If required" />
            <x-settings.input label="From address" name="smtp[from]" type="email" :value="$s['from'] ?? ''" placeholder="noreply@yourdomain.com" />
            <x-settings.input label="From name" name="smtp[from_name]" :value="$s['from_name'] ?? 'DomainDash'" placeholder="DomainDash" />
        </div>
    </x-settings.form-card>

    <form method="POST" action="{{ route('admin.settings.smtp-test') }}">
        @csrf
        <x-ui.card title="Send a test email" description="Uses the saved SMTP settings. Save first if you changed them.">
            <x-settings.input label="Test recipient" name="to" type="email" id="smtp_test_to" placeholder="test@yourdomain.com" required />
            <x-slot name="footer">
                <div class="dd-set-actions"><x-ui.button type="submit" variant="secondary">Send test email</x-ui.button></div>
            </x-slot>
        </x-ui.card>
    </form>
</div>
