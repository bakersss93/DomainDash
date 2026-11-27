@props([
    'title' => null,    // bold first line of the summary strip
    'meta' => null,     // muted second line (string, or the `meta` slot for markup)
    'aside' => null,    // right-aligned muted text (string, or the `aside` slot)
    'colspan' => 6,     // columns the expanded row spans
    'detailsFor' => null, // optional data-details-for hook on the inner wrapper (lazy loaders)
])

{{--
    Expandable table-row detail: summary strip + action grid (default slot).
    Renders the whole <tr>; pass the data-*-panel attribute your toggle script looks for.
    The row is hidden until a script adds the `open` class to it.

    <x-ui.row-detail data-domain-panel="{{ $id }}" :title="$name" :meta="$status" aside="DNS: …">
        <x-ui.action-grid> <x-ui.action-tile …>Overview</x-ui.action-tile> </x-ui.action-grid>
    </x-ui.row-detail>
--}}
<tr {{ $attributes->class(['ui-row-panel']) }}>
    <td colspan="{{ $colspan }}">
        <div class="ui-row-detail"@if($detailsFor !== null) data-details-for="{{ $detailsFor }}"@endif>
            <div class="ui-row-detail__summary">
                <div class="ui-row-detail__main">
                    @if($title !== null)<div class="ui-row-detail__title">{{ $title }}</div>@endif
                    @if(isset($meta) && ! is_string($meta) && $meta->isNotEmpty())
                        <div class="ui-row-detail__meta">{{ $meta }}</div>
                    @elseif(is_string($meta) && $meta !== '')
                        <div class="ui-row-detail__meta">{{ $meta }}</div>
                    @endif
                </div>
                @if(isset($aside) && ! is_string($aside) && $aside->isNotEmpty())
                    <div class="ui-row-detail__aside">{{ $aside }}</div>
                @elseif(is_string($aside) && $aside !== '')
                    <div class="ui-row-detail__aside">{{ $aside }}</div>
                @endif
            </div>
            {{ $slot }}
        </div>
    </td>
</tr>
