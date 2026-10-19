@props([
    'variant' => 'primary', // primary | secondary | danger | ghost
    'size' => 'md',         // sm | md | lg
    'href' => null,         // set => renders <a> (navigation); otherwise <button> (action)
    'type' => 'button',
    'icon' => false,        // square icon-only button (requires aria-label)
    'block' => false,
])

@php
    $classes = 'ui-btn ui-btn--'.$variant
        .($size !== 'md' ? ' ui-btn--'.$size : '')
        .($icon ? ' ui-btn--icon' : '')
        .($block ? ' ui-btn--block' : '');
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
