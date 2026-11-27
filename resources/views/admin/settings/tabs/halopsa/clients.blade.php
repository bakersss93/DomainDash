<x-ui.card title="Client mappings" description="Which DomainDash client is which HaloPSA client. Saving a mapping queues an asset refresh for that client. Halo clients come from the local mirror: use Refresh to fetch the latest.">
    <x-ui.mapping-table
        id="halo-client-map"
        url="{{ route('admin.settings.mappings.index', 'halo.clients') }}"
        :searchable="true"
        :columns="[['key' => 'dash_client', 'label' => 'DomainDash client'], ['key' => 'remote', 'label' => 'HaloPSA client'], ['key' => 'remote_id', 'label' => 'Halo ID', 'muted' => true]]"
        :fields="[
            ['key' => 'dash_client', 'label' => 'DomainDash client', 'type' => 'combo', 'required' => true, 'source' => route('admin.settings.options', 'dd.clients'), 'placeholder' => 'Search clients'],
            ['key' => 'remote', 'label' => 'HaloPSA client', 'type' => 'combo', 'required' => true, 'source' => route('admin.settings.options', 'halo.clients'), 'placeholder' => 'Type to search, e.g. roc', 'emptyHint' => 'No Halo clients in the local mirror. Use Refresh from Halo first.'],
        ]"
        :labels="['title' => 'Client mapping', 'add' => 'Add', 'empty' => 'No clients are mapped to HaloPSA yet.', 'entity' => 'mapping']"
        :actions="[['label' => 'Refresh from Halo', 'url' => url('/admin/sync/halo/refresh'), 'method' => 'POST', 'success' => 'Refresh queued. The Halo client list updates when the worker finishes.']]" />
</x-ui.card>
