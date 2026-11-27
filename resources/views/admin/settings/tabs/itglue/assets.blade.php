@php
    $suggestUrl = str_replace('/0/fields', '/{id}/fields', route('admin.settings.itglue.type-fields', ['id' => 0]));
@endphp

<x-ui.card title="Asset mappings" description="Choose which DomainDash item types are documented in IT Glue, which flexible asset type each one goes to, and which of its fields receives each value. Types and fields are read live from IT Glue (cached 10 minutes).">
    <x-ui.mapping-table
        id="itglue-asset-map"
        mode="remote"
        :url="route('admin.settings.asset-mappings.index', 'itglue')"
        :fixed="true"
        :deletable="true"
        dialogSize="lg"
        :columns="[['key' => 'entity', 'label' => 'DomainDash item'], ['key' => 'asset_type', 'label' => 'Flexible asset type'], ['key' => 'fields', 'label' => 'Fields mapped', 'muted' => true], ['key' => 'enabled', 'label' => 'Enabled', 'badge' => ['Enabled' => 'success', 'Off' => 'neutral']]]"
        :fields="[
            ['key' => 'entity', 'label' => 'DomainDash item', 'type' => 'combo', 'locked' => true],
            ['key' => 'enabled', 'label' => 'Sync this item type to IT Glue', 'type' => 'checkbox', 'help' => 'Scheduled and manual syncs only push item types that are turned on.'],
            ['key' => 'asset_type', 'label' => 'Flexible asset type', 'type' => 'combo', 'refresh' => true, 'source' => route('admin.settings.options', 'itglue.flexible-types'), 'placeholder' => 'Type to search', 'help' => 'Required for syncing this item type to IT Glue.', 'emptyHint' => 'No flexible asset types. Save the connection first.'],
            ['key' => 'fields', 'label' => 'DomainDash value to IT Glue field', 'type' => 'fieldmap', 'provider' => 'itglue', 'source' => route('admin.settings.options', 'itglue.flexible-fields'), 'typeParam' => 'type_id', 'typeField' => 'asset_type', 'suggestUrl' => $suggestUrl, 'suggestLabel' => 'Suggest from IT Glue', 'noneLabel' => 'Do not sync this value', 'help' => 'Only Text, Textbox and Date fields can be filled. Changing the mapping re-pushes every record of that item type on the next sync; fields that were mapped before keep their old values in IT Glue.'],
        ]"
        :labels="['title' => 'asset mapping', 'entity' => 'asset mapping', 'empty' => 'No asset mappings.']" />
</x-ui.card>
