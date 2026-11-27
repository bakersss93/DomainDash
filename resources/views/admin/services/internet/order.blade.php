@extends('layouts.app')

@section('content')
<div class="dd-page">
<div class="dd-card" style="max-width:760px;">
    <a href="{{ route('admin.services.internet.qualify') }}" style="font-size:13px;color:var(--dd-text-soft);text-decoration:none;">← Qualify Address</a>
    <h1 class="dd-page-title">New NBN Order</h1>

    @if(!empty($vocusProblem))
        <x-ui.alert variant="danger" title="Vocus is not ready" style="margin-bottom:16px;">{{ $vocusProblem }}
            <a href="{{ route('admin.settings.area', 'vocus') }}">Open settings</a>
        </x-ui.alert>
    @endif
    <p style="font-size:14px;color:var(--dd-text-soft);margin:0 0 16px;">Submit a new NBN connection request via Vocus Wholesale.</p>

    @if($errors->any())
        <x-ui.alert variant="danger" class="mb-4"><strong>Please correct the following errors:</strong>
            <ul style="margin:6px 0 0 16px;padding:0;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul></x-ui.alert>
    @endif

    <form method="POST" action="{{ route('admin.services.internet.order.store') }}">
        @csrf
        @include('admin.services.internet._order-fields', ['mode' => 'order'])

        <div style="display:flex;gap:10px;align-items:center;">
            <button type="submit" class="btn-accent" style="padding:10px 24px;font-size:14px;font-weight:600;"
                    @disabled($plans->isEmpty() || count($scopes) === 0)
                    onclick="return confirm('Submit this NBN order to Vocus? This will initiate a real service order.');">
                Submit Order →
            </button>
            <a href="{{ route('admin.services.internet') }}" style="font-size:14px;color:var(--dd-text-soft);text-decoration:none;">Cancel</a>
        </div>
    </form>
</div>
</div>
@endsection
