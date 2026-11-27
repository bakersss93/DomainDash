@props([
    'label' => null,
    'name' => null,
    'id' => null,
    'type' => 'text',
    'as' => 'input', // input | select | textarea
    'value' => null,
    'help' => null,
    'error' => null,
    'required' => false,
])

@php
    $dotted = $name ? trim(str_replace(['[]', '[', ']'], ['', '.', ''], $name), '.') : null;
    $id = $id ?? ($name ? 'field-'.str_replace('.', '-', $dotted) : 'field-'.\Illuminate\Support\Str::random(6));
    $error = $error ?? (($dotted && isset($errors)) ? $errors->first($dotted) : null);
    $current = $dotted ? old($dotted, $value) : $value;
    $describedBy = trim(($help ? $id.'-help ' : '').($error ? $id.'-error' : ''));
    $controlAttrs = $attributes->except('class')->merge(array_filter([
        'id' => $id,
        'name' => $name,
        'required' => $required ?: null,
        'aria-invalid' => $error ? 'true' : null,
        'aria-describedby' => $describedBy ?: null,
    ]));
@endphp

<div {{ $attributes->only('class')->class(['ui-field', 'has-error' => (bool) $error]) }}>
    @if ($label)
        <label for="{{ $id }}" class="ui-label">{{ $label }}@if ($required)<span class="ui-required" aria-hidden="true">*</span>@endif</label>
    @endif

    @if ($as === 'select')
        <select {{ $controlAttrs->merge(['class' => 'ui-input']) }}>{{ $slot }}</select>
    @elseif ($as === 'textarea')
        <textarea {{ $controlAttrs->merge(['class' => 'ui-input', 'rows' => 4]) }}>{{ $current }}</textarea>
    @elseif (trim((string) $slot) !== '')
        {{ $slot }}
    @else
        <input type="{{ $type }}" @if ($current !== null && $type !== 'password') value="{{ $current }}" @endif {{ $controlAttrs->merge(['class' => 'ui-input']) }}>
    @endif

    @if ($help)
        <p id="{{ $id }}-help" class="ui-help">{{ $help }}</p>
    @endif
    @if ($error)
        <p id="{{ $id }}-error" class="ui-error" role="alert">{{ $error }}</p>
    @endif
</div>
