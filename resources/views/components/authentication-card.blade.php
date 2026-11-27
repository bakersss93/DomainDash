<div class="min-h-screen flex flex-col sm:justify-center items-center px-4 py-8 bg-canvas">
    <div>
        {{ $logo }}
    </div>

    <div class="w-full sm:max-w-md mt-6 px-6 py-6 sm:px-8 sm:py-8 bg-surface text-ink border border-subtle shadow-md rounded-xl">
        {{ $slot }}
    </div>
</div>
