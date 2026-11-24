@props(['title' => null, 'description' => null, 'flush' => false])

{{--
  <x-ui.card title="..." description="..." :flush="true">
      <x-slot name="actions">header buttons</x-slot>
      body
      <x-slot name="footer">footer buttons</x-slot>
  </x-ui.card>
  The body is only rendered when it has content. Footer: put <span class="ui-card__hint"> helper text first (left) then buttons (right).
  `flush` removes body padding (tables edge-to-edge).
--}}
<section {{ $attributes->class(['ui-card', 'ui-card--flush' => $flush]) }}>
    @if ($title || isset($actions))
        <div class="ui-card__header">
            <div class="min-w-0">
                @if ($title)
                    <h2 class="ui-card__title">{{ $title }}</h2>
                @endif
                @if ($description)
                    <p class="ui-card__description">{{ $description }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    @if (trim((string) $slot) !== '')
        <div class="ui-card__body">{{ $slot }}</div>
    @endif

    @isset($footer)
        <div class="ui-card__footer">{{ $footer }}</div>
    @endisset
</section>
