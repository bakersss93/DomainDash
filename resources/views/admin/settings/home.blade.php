@extends('layouts.app')

@section('content')
    @include('admin.sync._live')
    <div class="dd-set-page">
        @include('admin.sync._worker-banner')

        <x-ui.page-header title="Settings" subtitle="Pick an area. Each one has its own page, with tabs for the detail." />

        @if ($errors->any())
            <x-ui.alert variant="danger" title="Please fix the following">
                <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-ui.alert>
        @endif

        @foreach ($categories as $category)
            <section aria-labelledby="cat-{{ \Illuminate\Support\Str::slug($category['title']) }}">
                <h2 class="dd-set-cat" id="cat-{{ \Illuminate\Support\Str::slug($category['title']) }}">{{ $category['title'] }}</h2>
                <div class="dd-set-grid">
                    @foreach ($category['areas'] as $slug)
                        @php $a = $areas[$slug]; $configured = $status[$slug] ?? null; @endphp
                        <a class="dd-set-tile" href="{{ \App\Support\Settings\SettingsAreas::href($slug) }}">
                            <span class="dd-set-tile__icon"><x-ui.icon :name="$a['icon']" :size="18" /></span>
                            <span class="min-w-0">
                                <h3 class="dd-set-tile__title">
                                    {{ $a['title'] }}
                                    @if ($configured === true)
                                        <x-ui.badge variant="success">Configured</x-ui.badge>
                                    @elseif ($configured === false)
                                        <x-ui.badge variant="neutral">Not set up</x-ui.badge>
                                    @endif
                                </h3>
                                <p class="dd-set-tile__desc">{{ $a['description'] }}</p>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endsection
