<div class="dd-set-split">
    <x-ui.card title="Domain assets" description="Push DomainDash domains to the mapped HaloPSA asset type, or pull assets back. Choose which domains in the dialog.">
        <x-slot name="footer">
            <x-ui.button onclick="openHaloSyncModal()">Sync domains to Halo</x-ui.button>
        </x-slot>
    </x-ui.card>

    @php($__assets = app(\App\Services\Sync\SyncDispatcher::class)->enabledAssetEntities('halo'))
    @foreach ($__assets as $__entity)
        <x-ui.card :title="\App\Services\Assets\AssetEntity::label($__entity)" :description="'Push DomainDash ' . \App\Services\Assets\AssetEntity::plural($__entity) . ' to the mapped HaloPSA asset type as assets. Choose which ones in the dialog. Unchanged records are skipped.'">
            <x-slot name="footer">
                <x-ui.button onclick="openAssetSyncModal('halo', '{{ $__entity }}')">Sync to Halo</x-ui.button>
            </x-slot>
        </x-ui.card>
    @endforeach
    <x-ui.card title="Refresh from HaloPSA" description="Queue a background refresh of the local Halo mirror (clients and domain assets). The Clients tab and mappings read from this mirror.">
        <x-slot name="footer">
            <span class="ui-card__hint">Progress appears below.</span>
            <x-ui.button variant="secondary" onclick="ddRefreshMirror('/admin/sync/halo/refresh', this)">Refresh now</x-ui.button>
        </x-slot>
    </x-ui.card>

    <x-ui.card title="Schedule" description="Recurring Halo syncs are set up with the other scheduled jobs.">
        <x-slot name="footer">
            <x-ui.button variant="secondary" :href="route('admin.settings.area', 'sync')">Open sync schedule</x-ui.button>
        </x-slot>
    </x-ui.card>
</div>
<div class="dd-set-runs" data-dd-runs aria-live="polite"></div>
