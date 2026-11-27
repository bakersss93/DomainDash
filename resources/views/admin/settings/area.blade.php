@extends('layouts.app')

{{--
  One settings area with horizontal tabs (HaloPSA-style). Every tab is a partial in tabs/{area}/{tab}.blade.php.
  Tabs are deep-linkable: /admin/settings/halopsa#tickets or ?tab=tickets. Each form tab saves through
  SettingsController@update (partial merge); mapping tables save themselves through the mapping endpoints.
--}}
@section('content')
    @include('admin.sync._live')
    @php $tabs = $def['tabs']; $multi = count($tabs) > 1; @endphp
    <div class="dd-set-page">
        @include('admin.sync._worker-banner')

        <nav class="dd-set-crumbs" aria-label="Breadcrumb">
            <a href="{{ route('admin.settings') }}">Settings</a>
            <span aria-hidden="true">/</span>
            <span aria-current="page">{{ $def['title'] }}</span>
        </nav>

        <x-ui.page-header :title="$def['title']" :subtitle="$def['description']">
            <x-slot name="actions">
                <x-ui.button variant="secondary" size="sm" :href="route('admin.settings')">All settings</x-ui.button>
            </x-slot>
        </x-ui.page-header>

        @if ($errors->any())
            <x-ui.alert variant="danger" title="Please fix the following">
                <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-ui.alert>
        @endif

        <div data-dd-tabs data-initial="{{ $initialTab }}">
            @if ($multi)
                <div class="dd-tabs" role="tablist" aria-label="{{ $def['title'] }} sections">
                    @foreach ($tabs as $slug => $label)
                        <button type="button" class="dd-tabs__tab" role="tab" id="tab-{{ $slug }}" data-tab="{{ $slug }}"
                                aria-controls="panel-{{ $slug }}" aria-selected="{{ $slug === $initialTab ? 'true' : 'false' }}"
                                tabindex="{{ $slug === $initialTab ? '0' : '-1' }}">{{ $label }}</button>
                    @endforeach
                </div>
            @endif

            @foreach ($tabs as $slug => $label)
                <div class="dd-tabs__panel" id="panel-{{ $slug }}" data-tab-panel="{{ $slug }}"
                     @if ($multi) role="tabpanel" aria-labelledby="tab-{{ $slug }}" @endif
                     @if ($slug !== $initialTab) hidden @endif>
                    @include('admin.settings.tabs.' . $area . '.' . $slug)
                </div>
            @endforeach
        </div>
    </div>

    @if (in_array($area, ['halopsa', 'itglue', 'ip2whois'], true))
        @include('admin.settings.partials.sync-modals')
        @include('admin.settings.partials.sync-scripts')
    @endif
    @if ($area === 'vocus')
        @include('admin.settings.partials.vocus-modals')
    @endif
@endsection
