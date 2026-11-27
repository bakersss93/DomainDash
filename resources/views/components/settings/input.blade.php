@props(['label', 'name', 'value' => null, 'type' => 'text', 'help' => null, 'placeholder' => null, 'secret' => false, 'wide' => false, 'id' => null])
@php
    $dotted = trim(str_replace(['[]', '[', ']'], ['', '.', ''], $name), '.');
    $id = $id ?? 'f-' . str_replace('.', '-', $dotted);
    $val = $secret ? ($value ? '********' : '') : old($dotted, $value);
@endphp
<div class="dd-set-field {{ $wide ? 'dd-set-field--wide' : '' }}">
    <label for="{{ $id }}" class="dd-set-label">{{ $label }}</label>
    <input id="{{ $id }}" type="{{ $secret ? 'password' : $type }}" name="{{ $name }}" value="{{ $val }}"
           @if ($placeholder) placeholder="{{ $placeholder }}" @endif
           @if ($secret) autocomplete="new-password" data-dd-secret @endif
           @if ($help) aria-describedby="{{ $id }}-help" @endif
           {{ $attributes->class(['ui-input']) }}>
    @if ($help)<small id="{{ $id }}-help" class="dd-set-help">{{ $help }}</small>@endif
    @error($dotted)<p class="ui-error" role="alert">{{ $message }}</p>@enderror
</div>
