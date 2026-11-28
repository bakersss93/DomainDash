@extends('layouts.app')

@section('content')
@include('help._styles')
<div class="ui-page">
    <nav aria-label="Breadcrumb">
        <ol class="help-crumbs">
            <li><a href="{{ route('tickets.index') }}">Support</a></li>
            <li><a href="{{ route('help.index') }}">Help</a></li>
            <li aria-current="page">{{ $category['title'] }}</li>
        </ol>
    </nav>

    <x-ui.page-header :title="$category['title']" :subtitle="$category['summary'] !== '' ? $category['summary'] : null" />

    <div class="help-results">
        @foreach ($category['articles'] as $a)
            <div class="help-result">
                <a href="{{ route('help.show', [$a->category, $a->slug]) }}">{{ $a->title }}</a>
                @if ($a->summary !== '')<p>{{ $a->summary }}</p>@endif
            </div>
        @endforeach
    </div>

    <x-ui.card title="Still stuck?" description="If the guides don't answer your question, our team is happy to help." style="margin-top:1.5rem;">
        <x-ui.button :href="route('tickets.create')" variant="primary"><x-ui.icon name="plus" :size="16" /> Raise a ticket</x-ui.button>
    </x-ui.card>
</div>
@endsection
