@props(['active'])

@php
$classes = ($active ?? false)
            ? 'inline-flex items-center px-1 pt-1 border-b-2 border-primary text-sm font-semibold leading-5 text-ink focus:outline-none focus-visible:border-primary-hover transition duration-150 ease-in-out'
            : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-muted hover:text-ink hover:border-strong focus:outline-none focus-visible:text-ink transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }} @if ($active ?? false) aria-current="page" @endif>
    {{ $slot }}
</a>
