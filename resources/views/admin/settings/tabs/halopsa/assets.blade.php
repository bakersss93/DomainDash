@php
    $suggestUrl = str_replace('/0/fields', '/{id}/fields', route('admin.settings.halo.asset-type-fields', ['id' => 0]));
@endphp

<x-ui.card title="Asset mappings" description="Choose which DomainDash item types are documented as HaloPSA assets, which asset type each one goes to, and which Halo field receives each value. Asset types and fields are read live from HaloPSA (cached 10 minutes). Internet services are still billed through Halo subscriptions; this only writes asset records.">
    <x-ui.mapping-table
        id="halo-asset-map"
        mode="remote"
        :url="route('admin.settings.asset-mappings.index', 'halo')"
        :fixed="true"
        :deletable="true"
        dialogSize="lg"
        :columns="[['key' => 'entity', 'label' => 'DomainDash item'], ['key' => 'asset_type', 'label' => 'Halo asset type'], ['key' => 'fields', 'label' => 'Fields mapped', 'muted' => true], ['key' => 'enabled', 'label' => 'Enabled', 'badge' => ['Enabled' => 'success', 'Off' => 'neutral']]]"
        :fields="[
            ['key' => 'entity', 'label' => 'DomainDash item', 'type' => 'combo', 'locked' => true],
            ['key' => 'enabled', 'label' => 'Sync this item type to HaloPSA', 'type' => 'checkbox', 'help' => 'Scheduled and manual syncs only push item types that are turned on.'],
            ['key' => 'asset_type', 'label' => 'Halo asset type', 'type' => 'combo', 'refresh' => true, 'source' => route('admin.settings.options', 'halo.asset-types'), 'placeholder' => 'Type to search asset types', 'help' => 'Where assets for this item type are created. Domains without a choice use the asset type named Domain.', 'emptyHint' => 'No asset types. Save the HaloPSA connection first.'],
            ['key' => 'fields', 'label' => 'DomainDash value to Halo field', 'type' => 'fieldmap', 'provider' => 'halo', 'source' => route('admin.settings.options', 'halo.asset-fields'), 'typeParam' => 'asset_type_id', 'typeField' => 'asset_type', 'suggestUrl' => $suggestUrl, 'suggestLabel' => 'Suggest from Halo', 'keyFields' => true, 'noneLabel' => 'Do not sync this value', 'help' => 'Changing the asset type or a mapping re-pushes every record of that item type on the next sync (rate limited).'],
        ]"
        :labels="['title' => 'asset mapping', 'entity' => 'asset mapping', 'empty' => 'No asset mappings.']" />
</x-ui.card>

<x-ui.card title="Test mapping" description="Shows what would be sent for one domain using the saved Domains mapping. Nothing is sent to HaloPSA." data-dd-halo-preview>
    <div class="dd-set-fieldrow">
        <input type="text" class="ui-input" data-preview-domain list="haloPreviewDomains" placeholder="Domain name, e.g. example.com" aria-label="Domain to preview">
        <datalist id="haloPreviewDomains"></datalist>
        <button type="button" class="ui-btn ui-btn--secondary" data-preview-run>Test mapping</button>
    </div>
    <div data-preview-out role="status" aria-live="polite" style="margin-top:.75rem"></div>
</x-ui.card>
