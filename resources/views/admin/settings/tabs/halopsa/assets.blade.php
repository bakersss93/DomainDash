@php
    $haloRaw = is_array($settings['halo'] ?? null) ? $settings['halo'] : [];
    $haloMap = \App\Services\Halo\HaloAssetMapping::fromArray($haloRaw);
    $points = \App\Services\Halo\HaloAssetMapping::POINTS;
    $defaults = \App\Services\Halo\HaloAssetMapping::DEFAULT_FIELD_IDS;

    $mapRows = [];
    foreach ($points as $pk => $pl) {
        $fid = (int) ($haloMap->fieldIds[$pk] ?? 0);
        $name = trim((string) ($haloMap->fieldNames[$pk] ?? ''));
        $label = $fid === 0 ? "Don't sync" : ($name !== '' ? $name : 'Field #' . $fid);
        $mapRows[] = [
            'id' => $pk,
            'cells' => ['point' => $pl, 'halo_field' => $label],
            'values' => ['point' => ['id' => $pk, 'label' => $pl], 'halo_field' => ['id' => (string) $fid, 'label' => $label]],
        ];
    }
@endphp

<form method="POST" action="{{ route('admin.settings.update') }}">
    @csrf
    <input type="hidden" name="_return" value="/admin/settings/halopsa#assets">
    <x-ui.card title="Domain asset type and fields" description="Choose the HaloPSA asset type that holds domains, then which Halo field receives each DomainDash value. Asset types and fields are read live from HaloPSA (cached 10 minutes).">
        <div class="dd-set-stack">
            <div>
                <div data-dd-picker='@json(["source" => route("admin.settings.options", "halo.asset-types"), "label" => "Asset type for domains", "idInput" => "#halo_asset_type_id", "nameInput" => "#halo_asset_type_name", "placeholder" => "Type to search asset types", "help" => "Blank falls back to the asset type named Domain.", "emptyHint" => "No asset types. Save the connection first.", "syncFromInput" => true, "fallbackLabel" => "Asset type"])'></div>
                <input type="hidden" id="halo_asset_type_name" name="halo[asset_type_name]" value="{{ old('halo.asset_type_name', $haloRaw['asset_type_name'] ?? '') }}">
            </div>

            <div>
                <div class="dd-set-fieldrow" style="margin-bottom:.5rem">
                    <h3 class="dd-set-label" style="margin:0">DomainDash value to Halo field</h3>
                    <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-dd-suggest="halo" data-type-input="#halo_asset_type_id" data-prefix="halo_field_" data-name-prefix="halo_field_name_" data-table="#halo-field-map" data-status="halo-suggest-status">Suggest from Halo</button>
                    <span id="halo-suggest-status" class="dd-set-status" role="status" aria-live="polite"></span>
                </div>
                <x-ui.mapping-table
                    id="halo-field-map"
                    mode="local"
                    :fixed="true"
                    :rows="$mapRows"
                    :columns="[['key' => 'point', 'label' => 'DomainDash value'], ['key' => 'halo_field', 'label' => 'Halo field']]"
                    :fields="[
                        ['key' => 'point', 'label' => 'DomainDash value', 'type' => 'combo', 'locked' => true],
                        ['key' => 'halo_field', 'label' => 'Halo field', 'type' => 'combo', 'refresh' => true, 'source' => route('admin.settings.options', 'halo.asset-fields'), 'paramsFrom' => ['asset_type_id' => '#halo_asset_type_id'], 'staticOptions' => [['id' => '0', 'label' => 'Do not sync this value']], 'placeholder' => 'Type to search fields', 'help' => 'Leave empty to use the built-in default field id.', 'emptyHint' => 'Choose an asset type first, then its fields appear here.'],
                    ]"
                    :labels="['title' => 'Field mapping', 'entity' => 'field mapping']"
                    :emptyCells="['halo_field' => 'Built-in default']"
                    :bind="['prefix' => 'halo_field_', 'name-prefix' => 'halo_field_name_', 'field' => 'halo_field', 'column' => 'halo_field', 'defaults' => $defaults, 'empty' => 'Built-in default (id %d)']" />
                <p class="dd-set-help">Changing the asset type or any mapping re-pushes every domain on the next sync (one call per domain, rate limited). Save below to apply.</p>
            </div>

            <div>
                <h3 class="dd-set-label">Key fields</h3>
                <p class="dd-set-help" style="margin-top:0">HaloPSA shows up to three key fields per asset type in its lists. Pick which DomainDash value fills each.</p>
                <div class="dd-set-form" style="margin-top:.5rem">
                    @foreach ([1, 2, 3] as $slot)
                        <div class="dd-set-field">
                            <label for="halo_key_field_{{ $slot }}" class="dd-set-label">Key field {{ $slot }}</label>
                            <select id="halo_key_field_{{ $slot }}" name="halo[asset_key_fields][{{ $slot }}]" class="ui-input">
                                <option value="">Leave empty</option>
                                @foreach ($points as $pk => $pl)
                                    <option value="{{ $pk }}" @selected(($haloMap->keyFields[$slot] ?? '') === $pk)>{{ $pl }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>
            </div>

            <details class="dd-set-advanced">
                <summary>Advanced: manual IDs</summary>
                <p class="dd-set-help">The pickers above fill these boxes; you can also type ids directly (HaloPSA > Config > Asset Types). Use 0 to not sync a value; blank restores the built-in default (148/149/150/165).</p>
                <div class="dd-set-form">
                    <div class="dd-set-field">
                        <label for="halo_asset_type_id" class="dd-set-label">Asset type ID</label>
                        <input id="halo_asset_type_id" type="number" min="1" step="1" name="halo[asset_type_id]" class="ui-input" value="{{ old('halo.asset_type_id', $haloMap->typeId) }}" placeholder="blank = asset type named Domain">
                    </div>
                    @foreach ($points as $fieldKey => $fieldLabel)
                        <div class="dd-set-field">
                            <label for="halo_field_{{ $fieldKey }}" class="dd-set-label">{{ $fieldLabel }} field ID</label>
                            <input id="halo_field_{{ $fieldKey }}" type="number" min="0" step="1" class="ui-input" name="halo[asset_field_ids][{{ $fieldKey }}]" value="{{ old('halo.asset_field_ids.' . $fieldKey, $haloMap->fieldIds[$fieldKey]) }}">
                            <input type="hidden" id="halo_field_name_{{ $fieldKey }}" name="halo[asset_field_names][{{ $fieldKey }}]" value="{{ old('halo.asset_field_names.' . $fieldKey, $haloMap->fieldNames[$fieldKey] ?? '') }}">
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

<x-ui.card title="Test mapping" description="Shows what would be sent for one domain using the mapping as it is on this page. Nothing is sent to HaloPSA." data-dd-halo-preview>
    <div class="dd-set-fieldrow">
        <input type="text" class="ui-input" data-preview-domain list="haloPreviewDomains" placeholder="Domain name, e.g. example.com" aria-label="Domain to preview">
        <datalist id="haloPreviewDomains"></datalist>
        <button type="button" class="ui-btn ui-btn--secondary" data-preview-run>Test mapping</button>
    </div>
    <div data-preview-out role="status" aria-live="polite" style="margin-top:.75rem"></div>
</x-ui.card>
