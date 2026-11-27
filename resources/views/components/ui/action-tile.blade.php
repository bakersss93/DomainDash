@props([
    'href' => null,        // renders an <a>
    'action' => null,      // renders <form method=POST> + submit button (CSRF added)
    'method' => 'POST',    // spoofed via @method when not POST/GET
    'icon' => null,        // x-ui.icon name, or use the `svg` slot for custom markup
    'variant' => 'default', // default | danger
    'disabled' => false,
    'confirm' => null,     // confirm() text for form actions
    'target' => null,      // form/link target
    'type' => 'button',    // button type when neither href nor action is set
])

{{--
    One action in an expanded row or a detail page: rounded tile, icon left, label left.
    Extra attributes (data-*, onclick, id, class hooks) land on the interactive element
    (<a> or <button>), never on the wrapping form.
--}}
@php
    $classes = ['ui-action-tile', 'ui-action-tile--danger' => $variant === 'danger', 'is-disabled' => $disabled];
    $method = strtoupper($method);
@endphp
@php ob_start(); @endphp
@if($icon)<span class="ui-action-tile__icon"><x-ui.icon :name="$icon" :size="18" /></span>@elseif(isset($svg))<span class="ui-action-tile__icon">{{ $svg }}</span>@endif
<span class="ui-action-tile__label">{{ $slot }}</span>
@php $inner = new \Illuminate\Support\HtmlString(ob_get_clean()); @endphp
@if($action && ! $disabled)
    <form method="{{ $method === 'GET' ? 'GET' : 'POST' }}" action="{{ $action }}" class="ui-action-form"
          @if($target) target="{{ $target }}" @endif
          @if($confirm) onsubmit="return confirm({{ \Illuminate\Support\Js::from($confirm) }});" @endif>
        @if($method !== 'GET') @csrf @endif
        @if(! in_array($method, ['GET', 'POST'], true)) @method($method) @endif
        <button type="submit" {{ $attributes->class($classes) }}>{{ $inner }}</button>
    </form>
@elseif($disabled)
    <button type="button" disabled aria-disabled="true" {{ $attributes->class($classes) }}>{{ $inner }}</button>
@elseif($href)
    <a href="{{ $href }}" @if($target) target="{{ $target }}" @endif {{ $attributes->class($classes) }}>{{ $inner }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $inner }}</button>
@endif
