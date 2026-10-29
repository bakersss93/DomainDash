@props(['title' => 'Nothing here yet', 'text' => null, 'icon' => 'inbox'])

<div {{ $attributes->class(['ui-empty']) }}>
    <x-ui.icon :name="$icon" :size="44" class="ui-empty__icon" />
    <p class="ui-empty__title">{{ $title }}</p>
    @if ($text)
        <p class="ui-empty__text">{{ $text }}</p>
    @endif
    @if (trim((string) $slot) !== '')
        <div class="ui-empty__actions">{{ $slot }}</div>
    @endif
</div>
