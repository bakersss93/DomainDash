@props(['label', 'name', 'checked' => false, 'help' => null, 'id' => null])
@php
    $dotted = trim(str_replace(['[]', '[', ']'], ['', '.', ''], $name), '.');
    $id = $id ?? 'f-' . str_replace('.', '-', $dotted);
    $on = (bool) old($dotted, $checked);
@endphp
<div class="dd-set-field">
    <input type="hidden" name="{{ $name }}" value="0">
    <label class="dd-set-check" for="{{ $id }}">
        <input id="{{ $id }}" type="checkbox" name="{{ $name }}" value="1" @checked($on)>
        <span>{{ $label }}@if ($help)<small class="dd-set-help">{{ $help }}</small>@endif</span>
    </label>
</div>
