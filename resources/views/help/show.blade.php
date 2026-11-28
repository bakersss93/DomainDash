@extends('layouts.app')

@section('content')
@include('help._styles')
<div class="ui-page">
    <nav aria-label="Breadcrumb">
        <ol class="help-crumbs">
            <li><a href="{{ route('tickets.index') }}">Support</a></li>
            <li><a href="{{ route('help.index') }}">Help</a></li>
            @if ($category)<li><a href="{{ route('help.category', $category['slug']) }}">{{ $category['title'] }}</a></li>@endif
            <li aria-current="page">{{ $article->title }}</li>
        </ol>
    </nav>

    <x-ui.page-header :title="$article->title" :subtitle="$article->summary !== '' ? $article->summary : null" />

    <div class="help-layout">
        <article class="help-article">
            {{-- Escaped by the CommonMark renderer (html_input=escape, allow_unsafe_links=false): see HelpRenderer. --}}
            <div class="help-prose">{!! $html !!}</div>
        </article>

        <aside class="help-side" aria-label="Related help">
            @if (count($related))
                <x-ui.card>
                    <h2>Related articles</h2>
                    <ul>
                        @foreach ($related as $r)
                            <li><a href="{{ route('help.show', [$r->category, $r->slug]) }}">{{ $r->title }}</a></li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
            <x-ui.card>
                <div class="help-stuck">
                    <h2>Still stuck?</h2>
                    <p>If this article didn't solve it, tell us what you tried.</p>
                    <x-ui.button :href="route('tickets.create')" variant="primary">Raise a ticket</x-ui.button>
                </div>
            </x-ui.card>
            <a href="{{ route('help.index') }}" class="ui-btn ui-btn--ghost">Back to Help</a>
        </aside>
    </div>
</div>
@endsection
