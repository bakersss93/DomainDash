<x-ui.card title="Ticket type mappings" description="Which HaloPSA ticket type each service category uses when DomainDash creates, syncs or lists tickets. Types come live from HaloPSA (cached 10 minutes).">
    <x-ui.mapping-table
        id="halo-ticket-type-map"
        url="{{ route('admin.settings.mappings.index', 'halo.ticket-types') }}"
        :columns="[['key' => 'service_category', 'label' => 'Service category'], ['key' => 'halo_ticket_type', 'label' => 'Halo ticket type'], ['key' => 'ticket_class', 'label' => 'Ticket class']]"
        :fields="[
            ['key' => 'service_category', 'label' => 'Service category', 'type' => 'combo', 'required' => true, 'allowCustom' => true, 'source' => route('admin.settings.options', 'dd.service-categories'), 'help' => 'Pick one or type a new category.'],
            ['key' => 'halo_ticket_type', 'label' => 'Halo ticket type', 'type' => 'combo', 'required' => true, 'refresh' => true, 'source' => route('admin.settings.options', 'halo.ticket-types'), 'placeholder' => 'Type to search'],
            ['key' => 'ticket_class', 'label' => 'Ticket class', 'type' => 'select', 'options' => [['id' => 'Support/Issue', 'label' => 'Support/Issue'], ['id' => 'Service Request', 'label' => 'Service Request']], 'help' => 'How the portal classifies the request.'],
        ]"
        :labels="['title' => 'Ticket type mapping', 'add' => 'Add', 'empty' => 'No ticket type mappings yet.', 'entity' => 'mapping']" />
</x-ui.card>

<x-ui.card title="Status mappings" description="Translate HaloPSA ticket statuses into the labels shown in the Support Requests table.">
    <p class="dd-set-note">{{ count($settings['halo']['status_mappings'] ?? []) }} status mapping(s) configured.</p>
    <x-slot name="footer">
        <div class="dd-set-actions">
            <x-ui.mapping-table
                id="halo-status-map"
                modal="halo-status-map-modal"
                modalTitle="Status mappings"
                buttonLabel="Edit status mappings"
                class="ui-btn--primary"
                url="{{ route('admin.settings.mappings.index', 'halo.statuses') }}"
                :columns="[['key' => 'domaindash_status', 'label' => 'DomainDash status'], ['key' => 'halo_status', 'label' => 'Halo status']]"
                :fields="[
                    ['key' => 'domaindash_status', 'label' => 'DomainDash status', 'type' => 'combo', 'required' => true, 'allowCustom' => true, 'source' => route('admin.settings.options', 'dd.status-labels'), 'help' => 'The label customers see, e.g. Open, In Progress, Closed.'],
                    ['key' => 'halo_status', 'label' => 'Halo status', 'type' => 'combo', 'required' => true, 'refresh' => true, 'source' => route('admin.settings.options', 'halo.statuses'), 'placeholder' => 'Type to search'],
                ]"
                :labels="['title' => 'Status mapping', 'add' => 'Add', 'empty' => 'No status mappings yet.', 'entity' => 'mapping']" />
        </div>
    </x-slot>
</x-ui.card>
