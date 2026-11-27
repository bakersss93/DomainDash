@if ($errors->any())
    <div {{ $attributes->merge(['class' => 'ui-alert ui-alert--danger']) }} role="alert">
        <x-ui.icon name="x-circle" :size="20" class="ui-alert__icon" />
        <div class="ui-alert__body">
            <div class="ui-alert__title">{{ __('Whoops! Something went wrong.') }}</div>

            <ul class="mt-1 list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
