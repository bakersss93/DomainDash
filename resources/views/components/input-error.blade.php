@props(['for'])

@error($for)
    <p {{ $attributes->merge(['class' => 'ui-error']) }} role="alert">{{ $message }}</p>
@enderror
