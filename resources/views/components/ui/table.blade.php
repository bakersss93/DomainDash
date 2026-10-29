@props(['compact' => false])

{{-- Responsive table shell: horizontal scroll on narrow screens, consistent header and hover. --}}
<div {{ $attributes->class(['ui-table-wrap']) }}>
    <table @class(['ui-table', 'ui-table--compact' => $compact])>
        {{ $slot }}
    </table>
</div>
