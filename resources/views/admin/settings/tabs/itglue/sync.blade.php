<div class="dd-set-split">
    <x-ui.card title="Configuration items" description="Push DomainDash domains to the mapped flexible asset type. Choose which domains in the dialog.">
        <x-slot name="footer">
            <x-ui.button onclick="openItGlueSyncModal(); loadItGlueConfigs()">Sync domains to IT Glue</x-ui.button>
        </x-slot>
    </x-ui.card>

    <x-ui.card title="Refresh from IT Glue" description="Queue a background refresh of the local organisation mirror. The Organisations tab reads from it.">
        <x-slot name="footer">
            <span class="ui-card__hint">Progress appears below.</span>
            <x-ui.button variant="secondary" onclick="ddRefreshMirror('/admin/sync/itglue/refresh', this)">Refresh now</x-ui.button>
        </x-slot>
    </x-ui.card>

    <x-ui.card title="Schedule" description="Recurring IT Glue syncs are set up with the other scheduled jobs.">
        <x-slot name="footer">
            <x-ui.button variant="secondary" :href="route('admin.settings.area', 'sync')">Open sync schedule</x-ui.button>
        </x-slot>
    </x-ui.card>
</div>
<div class="dd-set-runs" data-dd-runs aria-live="polite"></div>
