@props(['icon', 'label', 'variant' => 'secondary', 'size' => 'sm', 'href' => null, 'type' => 'button'])

{{-- Icon-only action. `label` becomes both the accessible name and the tooltip. --}}
<x-ui.button :variant="$variant" :size="$size" :href="$href" :type="$type" :icon="true"
             :aria-label="$label" :title="$label" {{ $attributes }}>
    <x-ui.icon :name="$icon" :size="16" />
</x-ui.button>
