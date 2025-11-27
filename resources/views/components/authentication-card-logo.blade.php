@php
    $branding = \App\Models\Setting::get('branding', []);
    $logoPath = $branding['logo'] ?? null;
@endphp

<div class="flex justify-center mb-4">
    @if($logoPath)
        <img
            src="{{ \Illuminate\Support\Facades\Storage::url($logoPath) }}"
            alt="DomainDash Logo"
            style="max-width:200px;max-height:200px;height:auto;width:auto;"
        >
    @else
        {{-- Fallback if no logo has been uploaded yet --}}
        <span class="text-xl font-semibold">
            {{ config('app.name', 'DomainDash') }}
        </span>
    @endif
</div>
