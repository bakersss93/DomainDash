@props(['area', 'tab', 'title' => null, 'description' => null, 'save' => 'Save changes', 'multipart' => false])

{{-- A settings card that is its own form: posts to SettingsController@update, returns to this tab. One primary action: Save. --}}
<form method="POST" action="{{ route('admin.settings.update') }}" @if ($multipart) enctype="multipart/form-data" @endif {{ $attributes }}>
    @csrf
    <input type="hidden" name="_return" value="/admin/settings/{{ $area }}#{{ $tab }}">
    <x-ui.card :title="$title" :description="$description">
        {{ $slot }}
        <x-slot name="footer">
            <div class="dd-set-actions">
                @isset($extra){{ $extra }}@endisset
                <x-ui.button type="submit">{{ $save }}</x-ui.button>
            </div>
        </x-slot>
    </x-ui.card>
</form>
