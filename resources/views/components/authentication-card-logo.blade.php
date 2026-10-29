@php
    $branding = \App\Models\Setting::get('branding', []);
    $logoPath = $branding['logo'] ?? null;
    $logoUrl = $logoPath ? \Illuminate\Support\Facades\Storage::url($logoPath) : asset('images/jargon-logo.png');
@endphp

<div class="flex flex-col items-center mb-2">
    <img
        src="{{ $logoUrl }}"
        alt="{{ config('app.name', 'DomainDash') }} logo"
        class="h-20 w-auto rounded-xl"
    >
    <span class="mt-3 text-sm font-semibold tracking-wide text-muted uppercase">DomainDash</span>
</div>
