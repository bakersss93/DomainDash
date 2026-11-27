@props(['title', 'subtitle' => null])

<header {{ $attributes->merge(['class' => 'ui-page-header']) }}>
    <div class="min-w-0">
        <h1 class="ui-page-title">{{ $title }}</h1>
        @if ($subtitle)
            <p class="ui-page-subtitle">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        @if (trim((string) $actions) !== '')
            <div class="ui-page-actions">{{ $actions }}</div>
        @endif
    @endisset
</header>
