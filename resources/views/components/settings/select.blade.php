@props(['label', 'name', 'options' => [], 'value' => null, 'help' => null, 'id' => null, 'wide' => false])
@php
    $dotted = trim(str_replace(['[]', '[', ']'], ['', '.', ''], $name), '.');
    $id = $id ?? 'f-' . str_replace('.', '-', $dotted);
    $current = (string) old($dotted, $value);
@endphp
<div class="dd-set-field {{ $wide ? 'dd-set-field--wide' : '' }}">
    <label for="{{ $id }}" class="dd-set-label">{{ $label }}</label>
    <select id="{{ $id }}" name="{{ $name }}" @if ($help) aria-describedby="{{ $id }}-help" @endif {{ $attributes->class(['ui-input']) }}>
        @foreach ($options as $optValue => $optLabel)
            <option value="{{ $optValue }}" @selected($current === (string) $optValue)>{{ $optLabel }}</option>
        @endforeach
    </select>
    @if ($help)<small id="{{ $id }}-help" class="dd-set-help">{{ $help }}</small>@endif
    @error($dotted)<p class="ui-error" role="alert">{{ $message }}</p>@enderror
</div>
