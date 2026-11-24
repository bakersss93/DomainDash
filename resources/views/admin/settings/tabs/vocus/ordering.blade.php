<div class="dd-set-split">
    <x-ui.card title="PPPoE realms" description="Offered as the NBN domain on order and transfer forms. The username sent to Vocus is username@realm. Changes save immediately.">
        <x-ui.mapping-table
            id="vocus-realms-table"
            url="{{ route('admin.settings.mappings.index', 'vocus.realms') }}"
            :columns="[['key' => 'realm', 'label' => 'Realm']]"
            :fields="[['key' => 'realm', 'label' => 'Realm', 'type' => 'text', 'required' => true, 'placeholder' => 'e.g. wba.example.com.au', 'help' => 'A hostname such as example.com.au.']]"
            :labels="['title' => 'Realm', 'add' => 'Add', 'empty' => 'No realms yet.', 'entity' => 'realm']" />
    </x-ui.card>

    <x-ui.card title="Service scopes" description="Only enabled scopes appear on the order and transfer forms.">
        <p class="dd-set-note">{{ implode(', ', \App\Services\Vocus\VocusOptions::enabledScopes()) }}</p>
        <x-slot name="footer">
            <div class="dd-set-actions"><button type="button" class="ui-btn ui-btn--secondary" data-modal-open="vocus-scopes-modal">Edit scopes</button></div>
        </x-slot>
    </x-ui.card>
</div>
