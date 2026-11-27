@props([
    'variant' => null, // success | warning | danger | info | neutral | primary
    'status' => null,  // free-text status; mapped to a variant via App\Support\UiStatus
    'dot' => true,
])

@php
    if ($status !== null && $variant === null) {
        $variant = \App\Support\UiStatus::variant($status);
    }
    $variant ??= 'neutral';
    $content = ($slot->isEmpty() && $status !== null) ? \App\Support\UiStatus::label($status) : $slot;
@endphp

<span {{ $attributes->class(['ui-badge', 'ui-badge--'.$variant, 'ui-badge--dot' => $dot]) }}>{{ $content }}</span>
