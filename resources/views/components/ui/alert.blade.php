@props(['variant' => 'info', 'title' => null, 'dismissible' => false])

@php
    $icon = ['success' => 'check-circle', 'warning' => 'alert', 'danger' => 'x-circle', 'info' => 'info'][$variant] ?? 'info';
    $role = in_array($variant, ['danger', 'warning'], true) ? 'alert' : 'status';
@endphp

<div {{ $attributes->class(['ui-alert', 'ui-alert--'.$variant]) }} role="{{ $role }}" @if ($dismissible) data-ui-dismissible @endif>
    <x-ui.icon :name="$icon" :size="20" class="ui-alert__icon" />
    <div class="ui-alert__body">
        @if ($title)
            <div class="ui-alert__title">{{ $title }}</div>
        @endif
        {{ $slot }}
    </div>
    @if ($dismissible)
        <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm ui-btn--icon" aria-label="Dismiss" onclick="this.closest('.ui-alert').remove()">
            <x-ui.icon name="x" :size="16" />
        </button>
    @endif
</div>
