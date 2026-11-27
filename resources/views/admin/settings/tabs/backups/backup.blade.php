@php $s = $settings['backup'] ?? []; $n = $settings['notifications'] ?? []; $bs = \App\Services\Backup\BackupService::status(); @endphp
<x-settings.form-card area="backups" tab="backup" title="SFTP backups" description="The nightly database backup is uploaded here (php artisan domaindash:backup-run)." save="Save backup settings">
    <p class="text-sm mb-3">
        @if ($bs['last_success_at'])
            Last successful backup: <strong>{{ \Illuminate\Support\Carbon::parse($bs['last_success_at'])->format('Y-m-d H:i') }} UTC</strong>
            ({{ $bs['last_file'] }}, {{ number_format(((int) $bs['last_size']) / 1024, 0) }} KB).
        @else
            <strong>No successful backup yet.</strong>
        @endif
        @if ($bs['last_failure_at'] && $bs['last_error'])
            <br>Last attempt failed ({{ \Illuminate\Support\Carbon::parse($bs['last_failure_at'])->format('Y-m-d H:i') }} UTC): {{ $bs['last_error'] }}
        @endif
    </p>
    <div class="dd-set-form">
        <x-settings.input label="SFTP host" name="backup[host]" :value="$s['host'] ?? ''" placeholder="backup.example.com" />
        <x-settings.input label="Port" name="backup[port]" type="number" :value="$s['port'] ?? 22" />
        <x-settings.input label="Username" name="backup[username]" :value="$s['username'] ?? ''" />
        <x-settings.input label="Password" name="backup[password]" :value="$s['password'] ?? ''" :secret="true" />
        <x-settings.input label="SFTP host fingerprint" name="backup[host_fingerprint]" :value="$s['host_fingerprint'] ?? ''" placeholder="SHA256:abc123..." help="Required. Backups are refused if the server's key does not match. Get it with: ssh-keyscan -t ed25519 HOST | ssh-keygen -lf -" />
        <x-settings.input label="Backup encryption password" name="backup[encryption_password]" :value="$s['encryption_password'] ?? ''" :secret="true" help="Required, min 12 characters. The zip is AES-256 encrypted with it. Store it somewhere safe: without it a backup cannot be opened." />
        <x-settings.input label="Remote path" name="backup[path]" :value="$s['path'] ?? '/'" />
        <x-settings.input label="Keep backups (count)" name="backup[retention]" type="number" :value="$s['retention'] ?? 7" help="Older files are removed after each run." />
        <x-settings.input label="Run time" name="backup[time]" :value="$s['time'] ?? '02:00'" placeholder="HH:MM" help="24-hour, server timezone." />
        <x-settings.input label="Disk usage alert at (%)" name="notifications[disk_threshold_percent]" type="number" :value="$n['disk_threshold_percent'] ?? 90" />
    </div>
</x-settings.form-card>
