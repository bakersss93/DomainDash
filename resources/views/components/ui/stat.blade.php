@props(['label', 'value', 'hint' => null, 'variant' => null, 'href' => null])

@php $tag = $href ? 'a' : 'div'; @endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->class(['ui-stat', 'ui-stat--'.$variant => $variant]) }}>
    <span class="ui-stat__label">{{ $label }}</span>
    <span class="ui-stat__value">{{ $value }}</span>
    @if ($hint)
        <span class="ui-stat__hint">{{ $hint }}</span>
    @endif
</{{ $tag }}>
