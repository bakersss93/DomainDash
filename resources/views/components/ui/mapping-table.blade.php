@props([
    'id',
    'mode' => 'remote',        // remote: JSON endpoints | local: rows live in the page, saved with the surrounding form
    'url' => null,             // remote: GET list, POST create, PUT {id}, DELETE {id}
    'columns' => [],           // [{key,label,muted?}]
    'fields' => [],            // [{key,label,type:combo|select|text,source?,options?,staticOptions?,allowCustom?,required?,help?,refresh?,paramsFrom?,locked?}]
    'rows' => [],              // local mode: [{id,cells,values}]
    'fixed' => false,          // no Add/Delete (a fixed list of rows: edit only)
    'deletable' => false,      // with fixed: keep the delete (reset) row action
    'dialogSize' => null,      // 'lg' = wide edit dialog (asset mappings)
    'labels' => [],            // {title,add,empty,entity}
    'actions' => [],           // extra toolbar buttons [{label,url,method,reload?}]
    'searchable' => false,
    'perPage' => 10,
    'emptyCells' => [],        // local mode: text shown when a field is cleared
    'bind' => [],              // local mode: attributes read by dd-settings.js (data-dd-bind-prefix ...)
    'modal' => null,           // modal variant: the dialog id; the table is shown inside it
    'modalTitle' => null,
    'buttonLabel' => null,
])

{{--
  Mapping table (HaloPSA "Client Mappings" pattern): compact table, "1-10 of N" pager, green "+ Add",
  edit/delete on row hover, add/edit dialog with searchable selects. All behaviour in resources/js/dd-mapping-table.js.
  Spec: docs/review/18-settings-redesign.md.
--}}
@php
    $config = [
        'id' => $id, 'mode' => $mode, 'url' => $url, 'columns' => $columns, 'fields' => $fields,
        'rows' => $mode === 'local' ? array_values($rows) : [], 'fixed' => (bool) $fixed, 'deletable' => (bool) $deletable, 'dialogSize' => $dialogSize,
        'labels' => $labels, 'actions' => $actions, 'searchable' => (bool) $searchable,
        'perPage' => $perPage, 'emptyCells' => $emptyCells,
    ];
    $bindAttrs = collect($bind)->mapWithKeys(fn ($v, $k) => ['data-dd-bind-' . $k => is_array($v) ? json_encode($v) : $v])->all();
@endphp

@if ($modal)
    <button type="button" class="ui-btn {{ $attributes->get('class') ?: 'ui-btn--secondary' }}" data-modal-open="{{ $modal }}">{{ $buttonLabel ?? $modalTitle }}</button>
    <x-ui.modal :id="$modal" :title="$modalTitle ?? ($labels['title'] ?? 'Mappings')" size="lg">
        <div id="{{ $id }}" data-dd-mapping="{{ json_encode($config) }}" @foreach ($bindAttrs as $k => $v) {{ $k }}="{{ $v }}" @endforeach></div>
    </x-ui.modal>
@else
    <div id="{{ $id }}" {{ $attributes->only('class') }} data-dd-mapping="{{ json_encode($config) }}" @foreach ($bindAttrs as $k => $v) {{ $k }}="{{ $v }}" @endforeach></div>
@endif
