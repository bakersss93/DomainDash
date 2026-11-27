@props(['id' => null, 'maxWidth' => null])

<x-modal :id="$id" :maxWidth="$maxWidth" {{ $attributes }}>
    <div class="px-6 py-5">
        <div class="text-lg font-semibold text-ink">
            {{ $title }}
        </div>

        <div class="mt-4 text-sm text-muted">
            {{ $content }}
        </div>
    </div>

    <div class="flex flex-row justify-end gap-2 px-6 py-4 bg-surface-soft border-t border-subtle text-end">
        {{ $footer }}
    </div>
</x-modal>
