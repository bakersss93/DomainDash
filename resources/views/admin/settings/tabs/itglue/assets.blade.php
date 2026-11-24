@php
    $i = is_array($settings['itglue'] ?? null) ? $settings['itglue'] : [];
    $points = ['domain' => 'Domain name', 'name_servers' => 'Name servers', 'expiry' => 'Expiry date', 'whois' => 'WHOIS / registrant summary', 'dns' => 'DNS records'];
    $traits = is_array($i['flexible_asset_traits'] ?? null) ? $i['flexible_asset_traits'] : \App\Services\ItGlue\ItGlueClient::DEFAULT_TRAITS;
    $mapRows = [];
    foreach ($points as $pk => $pl) {
        $key = trim((string) ($traits[$pk] ?? ''));
        $label = $key !== '' ? $key : 'Not synced';
        $mapRows[] = [
            'id' => $pk,
            'cells' => ['point' => $pl, 'trait' => $label],
            'values' => ['point' => ['id' => $pk, 'label' => $pl], 'trait' => ['id' => $key, 'label' => $label]],
        ];
    }
@endphp

<form method="POST" action="{{ route('admin.settings.update') }}">
    @csrf
    <input type="hidden" name="_return" value="/admin/settings/itglue#assets">
    <x-ui.card title="Flexible asset type and fields" description="Choose the IT Glue flexible asset type that holds domains, then which of its fields receives each value. Types and fields are read live from IT Glue (cached 10 minutes).">
        <div class="dd-set-stack">
            <div>
                <div data-dd-picker="{{ json_encode(["source" => route("admin.settings.options", "itglue.flexible-types"), "label" => "Flexible asset type for domains", "idInput" => "#itglue_flexible_asset_type_id", "nameInput" => "#itglue_flexible_asset_type_name", "placeholder" => "Type to search", "help" => "Required for syncing domains to IT Glue.", "emptyHint" => "No flexible asset types. Save the connection first.", "syncFromInput" => true, "fallbackLabel" => "Flexible asset type"]) }}"></div>
                <input type="hidden" id="itglue_flexible_asset_type_name" name="itglue[flexible_asset_type_name]" value="{{ old('itglue.flexible_asset_type_name', $i['flexible_asset_type_name'] ?? '') }}">
            </div>

            <div>
                <div class="dd-set-fieldrow" style="margin-bottom:.5rem">
                    <h3 class="dd-set-label" style="margin:0">DomainDash value to IT Glue field</h3>
                    <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-dd-suggest="itglue" data-type-input="#itglue_flexible_asset_type_id" data-prefix="itglue_trait_" data-table="#itglue-trait-map" data-status="itg-suggest-status">Suggest from IT Glue</button>
                    <span id="itg-suggest-status" class="dd-set-status" role="status" aria-live="polite"></span>
                </div>
                <x-ui.mapping-table
                    id="itglue-trait-map"
                    mode="local"
                    :fixed="true"
                    :rows="$mapRows"
                    :columns="[['key' => 'point', 'label' => 'DomainDash value'], ['key' => 'trait', 'label' => 'IT Glue field (trait key)']]"
                    :fields="[
                        ['key' => 'point', 'label' => 'DomainDash value', 'type' => 'combo', 'locked' => true],
                        ['key' => 'trait', 'label' => 'IT Glue field', 'type' => 'combo', 'refresh' => true, 'source' => route('admin.settings.options', 'itglue.flexible-fields'), 'paramsFrom' => ['type_id' => '#itglue_flexible_asset_type_id'], 'placeholder' => 'Type to search fields', 'help' => 'Only Text, Textbox and Date fields can be filled. Leave empty to not sync this value.', 'emptyHint' => 'Choose a flexible asset type first, then its fields appear here.'],
                    ]"
                    :labels="['title' => 'Field mapping', 'entity' => 'field mapping']"
                    :emptyCells="['trait' => 'Not synced']"
                    :bind="['prefix' => 'itglue_trait_', 'field' => 'trait', 'column' => 'trait', 'keylabel' => '1', 'empty' => 'Not synced']" />
                <p class="dd-set-help">Changing the mapping re-pushes every domain on the next sync; fields that were mapped before keep their old values in IT Glue. Save below to apply.</p>
            </div>

            <details class="dd-set-advanced">
                <summary>Advanced: manual IDs and trait keys</summary>
                <p class="dd-set-help">The pickers above fill these boxes; you can type values directly. A trait key is the "name-key" of a field. Blank means that value is not synced.</p>
                <div class="dd-set-form">
                    <div class="dd-set-field">
                        <label for="itglue_flexible_asset_type_id" class="dd-set-label">Flexible asset type ID</label>
                        <input id="itglue_flexible_asset_type_id" type="text" inputmode="numeric" name="itglue[flexible_asset_type_id]" class="ui-input" value="{{ old('itglue.flexible_asset_type_id', $i['flexible_asset_type_id'] ?? '') }}" placeholder="e.g. 4521154692859938">
                    </div>
                    @foreach ($points as $pk => $pl)
                        <div class="dd-set-field">
                            <label for="itglue_trait_{{ $pk }}" class="dd-set-label">{{ $pl }} trait key</label>
                            <input id="itglue_trait_{{ $pk }}" type="text" class="ui-input" name="itglue[flexible_asset_traits][{{ $pk }}]" value="{{ old('itglue.flexible_asset_traits.' . $pk, $traits[$pk] ?? '') }}" placeholder="{{ \App\Services\ItGlue\ItGlueClient::DEFAULT_TRAITS[$pk] }}">
                        </div>
                    @endforeach
                </div>
            </details>
        </div>
        <x-slot name="footer">
            <div class="dd-set-actions"><x-ui.button type="submit">Save asset mapping</x-ui.button></div>
        </x-slot>
    </x-ui.card>
</form>
