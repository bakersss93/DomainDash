@php $s = $settings['backup'] ?? []; $n = $settings['notifications'] ?? []; @endphp
<x-settings.form-card area="backups" tab="backup" title="SFTP backups" description="The nightly database backup is uploaded here (php artisan domaindash:backup-run)." save="Save backup settings">
    <div class="dd-set-form">
        <x-settings.input label="SFTP host" name="backup[host]" :value="$s['host'] ?? ''" placeholder="backup.example.com" />
        <x-settings.input label="Port" name="backup[port]" type="number" :value="$s['port'] ?? 22" />
        <x-settings.input label="Username" name="backup[username]" :value="$s['username'] ?? ''" />
        <x-settings.input label="Password" name="backup[password]" :value="$s['password'] ?? ''" :secret="true" />
        <x-settings.input label="Remote path" name="backup[path]" :value="$s['path'] ?? '/'" />
        <x-settings.input label="Keep backups (count)" name="backup[retention]" type="number" :value="$s['retention'] ?? 7" help="Older files are removed after each run." />
        <x-settings.input label="Run time" name="backup[time]" :value="$s['time'] ?? '02:00'" placeholder="HH:MM" help="24-hour, server timezone." />
        <x-settings.input label="Disk usage alert at (%)" name="notifications[disk_threshold_percent]" type="number" :value="$n['disk_threshold_percent'] ?? 90" />
    </div>
</x-settings.form-card>
