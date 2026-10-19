@props(['id' => null, 'maxWidth' => null])

<x-modal :id="$id" :maxWidth="$maxWidth" {{ $attributes }}>
    <div class="px-6 pt-5 pb-4">
        <div class="sm:flex sm:items-start">
            <div class="mx-auto shrink-0 flex items-center justify-center size-12 rounded-full bg-danger-soft text-danger-fg sm:mx-0 sm:size-10">
                <x-ui.icon name="alert" :size="22" />
            </div>

            <div class="mt-3 text-center sm:mt-0 sm:ms-4 sm:text-start">
                <h3 class="text-lg font-semibold text-ink">
                    {{ $title }}
                </h3>

                <div class="mt-4 text-sm text-muted">
                    {{ $content }}
                </div>
            </div>
        </div>
    </div>

    <div class="flex flex-row justify-end gap-2 px-6 py-4 bg-surface-soft border-t border-subtle text-end">
        {{ $footer }}
    </div>
</x-modal>
