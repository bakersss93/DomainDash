<div class="dd-set-split">
    <x-ui.card title="Domain assets" description="Push DomainDash domains to the mapped HaloPSA asset type, or pull assets back. Choose which domains in the dialog.">
        <x-slot name="footer">
            <div class="dd-set-actions"><x-ui.button onclick="openHaloSyncModal()">Sync domains to Halo</x-ui.button></div>
        </x-slot>
    </x-ui.card>

    <x-ui.card title="Refresh from HaloPSA" description="Queue a background refresh of the local Halo mirror (clients and domain assets). The Clients tab and mappings read from this mirror.">
        <p class="dd-set-note">Progress appears below the button.</p>
        <x-slot name="footer">
            <div class="dd-set-actions"><x-ui.button variant="secondary" onclick="ddRefreshMirror('/admin/sync/halo/refresh', this)">Refresh now</x-ui.button></div>
            <div data-dd-runs></div>
        </x-slot>
    </x-ui.card>

    <x-ui.card title="Schedule" description="Recurring Halo syncs are set up with the other scheduled jobs.">
        <x-slot name="footer">
            <div class="dd-set-actions"><x-ui.button variant="ghost" :href="route('admin.settings.area', 'sync')">Open sync schedule</x-ui.button></div>
        </x-slot>
    </x-ui.card>
</div>
