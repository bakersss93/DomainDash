@extends('layouts.app')

@section('content')
@include('help._styles')
<div class="ui-page">
    <x-ui.page-header title="Help" subtitle="Step-by-step guides for using the portal. Can't find it? Raise a ticket and we'll help." />

    <form method="GET" action="{{ route('help.index') }}" class="help-search" role="search">
        <label for="help-search" class="sr-only">Search help articles</label>
        <input id="help-search" class="ui-input" type="search" name="q" value="{{ $query }}" maxlength="120" placeholder="Search help, for example: DNS, MFA, ticket" autocomplete="off">
        <button type="submit" class="ui-btn ui-btn--primary"><x-ui.icon name="search" :size="16" /> Search</button>
        @if ($query !== '')
            <a href="{{ route('help.index') }}" class="ui-btn ui-btn--ghost">Clear</a>
        @endif
    </form>

    @if ($results !== null)
        <h2 class="help-section-title" style="margin-top:0;">{{ count($results) }} {{ \Illuminate\Support\Str::plural('result', count($results)) }} for "{{ $query }}"</h2>
        @if (count($results) === 0)
            <x-ui.card>
                <x-ui.empty-state title="No articles found" text="Try different words, or browse the categories below." icon="search" />
            </x-ui.card>
        @else
            <div class="help-results" style="margin-bottom:1.5rem;">
                @foreach ($results as $hit)
                    <div class="help-result">
                        <a href="{{ route('help.show', [$hit['article']->category, $hit['article']->slug]) }}">{{ $hit['article']->title }}</a>
                        <x-ui.badge variant="neutral" :dot="false">{{ config('help.categories.'.$hit['article']->category.'.title', \Illuminate\Support\Str::headline($hit['article']->category)) }}</x-ui.badge>
                        <p>{{ $hit['excerpt'] }}</p>
                    </div>
                @endforeach
            </div>
        @endif
        <h2 class="help-section-title">Browse all topics</h2>
    @endif

    @forelse ($groups as $audience => $cats)
        @if (count($groups) > 1)
            <h2 class="help-section-title">{{ $audience === 'integration' ? 'Integration set-up (staff)' : 'Using the portal' }}</h2>
            <p class="help-section-note">{{ $audience === 'integration' ? 'Configuration guides for the connections and settings managed by staff.' : 'How-to guides for everyday tasks.' }}</p>
        @endif
        <div class="help-grid">
            @foreach ($cats as $cat)
                <section class="help-cat" aria-labelledby="cat-{{ $cat['slug'] }}">
                    <h3 id="cat-{{ $cat['slug'] }}"><a href="{{ route('help.category', $cat['slug']) }}">{{ $cat['title'] }}</a></h3>
                    @if ($cat['summary'] !== '')<p>{{ $cat['summary'] }}</p>@endif
                    <ul>
                        @foreach (array_slice($cat['articles'], 0, 6) as $a)
                            <li><a href="{{ route('help.show', [$a->category, $a->slug]) }}">{{ $a->title }}</a></li>
                        @endforeach
                        @if (count($cat['articles']) > 6)
                            <li><a href="{{ route('help.category', $cat['slug']) }}">All {{ count($cat['articles']) }} articles</a></li>
                        @endif
                    </ul>
                </section>
            @endforeach
        </div>
    @empty
        <x-ui.card>
            <x-ui.empty-state title="No help articles yet" text="Articles will appear here when they are added." icon="inbox" />
        </x-ui.card>
    @endforelse

    <x-ui.card title="Still stuck?" description="If the guides don't answer your question, our team is happy to help." style="margin-top:1.5rem;">
        <x-ui.button :href="route('tickets.create')" variant="primary"><x-ui.icon name="plus" :size="16" /> Raise a ticket</x-ui.button>
    </x-ui.card>
</div>
@endsection
