@php
    $b = $settings['branding'] ?? [];
    $palette = [
        'Light mode' => ['primary' => ['Primary', '#26272c'], 'accent' => ['Accent', '#2f7d4b'], 'bg' => ['Background', '#f5f6f8'], 'text' => ['Text', '#1b1d22'], 'button_text' => ['Button text', '#ffffff']],
        'Dark mode' => ['primary_dark' => ['Primary', '#0b1220'], 'accent_dark' => ['Accent', '#86b57a'], 'bg_dark' => ['Background', '#0f172a'], 'text_dark' => ['Text', '#e2e8f0'], 'button_text_dark' => ['Button text', '#0f1a0f']],
    ];
@endphp
<x-settings.form-card area="branding" tab="branding" title="Colours and logo" description="Applied across the dashboard and client-facing pages." save="Save branding" :multipart="true">
    <div class="dd-set-field" style="margin-bottom:1rem;">
        <label for="branding-company_name" class="dd-set-label">Company name</label>
        <input id="branding-company_name" type="text" name="branding[company_name]" maxlength="100" value="{{ old('branding.company_name', $b['company_name'] ?? '') }}" placeholder="{{ config('app.name') }}" class="ui-input">
        <small class="dd-set-help">Shown on the login page ("Log in to ...") and in Help articles. Leave blank to use the application name.</small>
        @error('branding.company_name')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
    </div>

    <div class="dd-set-split">
        @foreach ($palette as $mode => $colours)
            <fieldset class="dd-set-colours">
                <legend class="dd-set-label">{{ $mode }}</legend>
                @foreach ($colours as $key => [$label, $default])
                    <label class="dd-set-colour" for="branding-{{ $key }}">
                        <input id="branding-{{ $key }}" type="color" name="branding[{{ $key }}]" value="{{ old('branding.' . $key, $b[$key] ?? $default) }}">
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </fieldset>
        @endforeach
    </div>

    <div class="dd-set-field" style="margin-top:1rem;">
        <label for="branding_logo" class="dd-set-label">Logo</label>
        @if (!empty($b['logo']))
            <img src="{{ Storage::url($b['logo']) }}" alt="Current logo" class="dd-set-logo">
        @endif
        <input id="branding_logo" type="file" name="branding_logo" accept="image/png,image/jpeg,image/svg+xml" class="ui-input">
        <small class="dd-set-help">PNG preferred, about 200px wide; height scales. Up to 2 MB.</small>
        @error('branding_logo')<p class="ui-error" role="alert">{{ $message }}</p>@enderror
    </div>
</x-settings.form-card>
