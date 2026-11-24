<x-ui.card title="Organisation mappings" description="Which DomainDash client is which IT Glue organisation. Organisations come from the local mirror: use Refresh to fetch the latest.">
    <x-ui.mapping-table
        id="itglue-org-map"
        url="{{ route('admin.settings.mappings.index', 'itglue.organisations') }}"
        :searchable="true"
        :columns="[['key' => 'dash_client', 'label' => 'DomainDash client'], ['key' => 'remote', 'label' => 'IT Glue organisation'], ['key' => 'remote_id', 'label' => 'Organisation ID', 'muted' => true]]"
        :fields="[
            ['key' => 'dash_client', 'label' => 'DomainDash client', 'type' => 'combo', 'required' => true, 'source' => route('admin.settings.options', 'dd.clients'), 'placeholder' => 'Search clients'],
            ['key' => 'remote', 'label' => 'IT Glue organisation', 'type' => 'combo', 'required' => true, 'source' => route('admin.settings.options', 'itglue.organisations'), 'placeholder' => 'Type to search', 'emptyHint' => 'No organisations in the local mirror. Use Refresh from IT Glue first.'],
        ]"
        :labels="['title' => 'Organisation mapping', 'add' => 'Add', 'empty' => 'No clients are mapped to IT Glue yet.', 'entity' => 'mapping']"
        :actions="[['label' => 'Refresh from IT Glue', 'url' => url('/admin/sync/itglue/refresh'), 'method' => 'POST', 'success' => 'Refresh queued. The organisation list updates when the worker finishes.']]" />
</x-ui.card>
