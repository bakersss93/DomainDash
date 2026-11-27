@props(['id', 'title', 'size' => 'md', 'open' => false])

{{--
  Unified dialog (same markup as the .dd-modal pattern used on domains / SSL / clients pages).
  Open with any element carrying data-modal-open="{{ $id }}"; close with [data-modal-close],
  Esc or a click on the scrim. See resources/js/app.js.
--}}
<div id="{{ $id }}" class="dd-modal ui-modal" data-ui-modal style="display: {{ $open ? 'flex' : 'none' }};">
    <div class="dd-modal-backdrop" data-modal-close></div>
    <div {{ $attributes->class(['ui-modal__dialog', 'ui-modal__dialog--lg' => $size === 'lg']) }}
         role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title" tabindex="-1">
        <div class="ui-modal__header">
            <h2 id="{{ $id }}-title" class="ui-modal__title">{{ $title }}</h2>
            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm ui-btn--icon" data-modal-close aria-label="Close dialog">
                <x-ui.icon name="x" :size="18" />
            </button>
        </div>

        <div>{{ $slot }}</div>

        @isset($footer)
            <div class="ui-modal__footer">{{ $footer }}</div>
        @endisset
    </div>
</div>
