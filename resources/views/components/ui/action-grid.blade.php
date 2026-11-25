@props(['label' => 'Actions'])
{{-- Responsive grid for <x-ui.action-tile>: 4 columns, 2 below 900px, 1 below 520px. --}}
<div {{ $attributes->class(['ui-action-grid']) }} role="group" aria-label="{{ $label }}">
    {{ $slot }}
</div>
