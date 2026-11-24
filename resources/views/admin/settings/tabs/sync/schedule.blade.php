@php
    $sched = $settings['sync_schedule'] ?? [];
    $frequencies = ['hourly' => 'Hourly', 'daily' => 'Daily', 'weekly' => 'Weekly'];
    $jobs = [
        'sync_domains' => 'Sync domains from Synergy',
        'sync_hosting_services' => 'Sync hosting services',
        'sync_halo_assets' => 'Sync domain assets to and from HaloPSA',
        'sync_itglue' => 'Sync IT Glue domain assets',
    ];
@endphp
<x-settings.form-card area="sync" tab="schedule" title="Scheduled syncs" :description="'Server timezone: '.date_default_timezone_get().'. Cron must run php artisan schedule:run every minute.'" save="Save schedule">
    <div class="dd-set-sched">
        @foreach ($jobs as $key => $label)
            <div class="dd-set-sched__row">
                <x-settings.check :label="$label" :name="'sync_schedule['.$key.'][enabled]'" :checked="!empty($sched[$key]['enabled'])" />
                <x-settings.select label="Frequency" :name="'sync_schedule['.$key.'][frequency]'" :options="$frequencies" :value="$sched[$key]['frequency'] ?? 'daily'" />
                <x-settings.input label="Run time" :name="'sync_schedule['.$key.'][time]'" :value="$sched[$key]['time'] ?? '02:00'" placeholder="HH:MM" maxlength="5" pattern="^([01]\d|2[0-3]):[0-5]\d$" title="24-hour HH:MM" />
            </div>
        @endforeach
    </div>
    <p class="dd-set-help">Hourly uses the minutes of the run time; weekly runs every Monday.</p>
</x-settings.form-card>
