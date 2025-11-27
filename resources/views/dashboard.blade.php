@extends('layouts.app')
@section('content')
<h1>Dashboard</h1>
@php
$clientIds = auth()->user()->hasRole('Administrator') ? \App\Models\Client::pluck('id') : auth()->user()->clients()->pluck('clients.id');
$domains = \App\Models\Domain::whereIn('client_id',$clientIds)->orderBy('name')->limit(50)->get();
@endphp

<form method="GET">
    <input type="text" name="q" placeholder="Search domains/services" value="{{ request('q') }}">
</form>

<table border="1" cellpadding="6" cellspacing="0" width="100%" style="margin-top:12px;">
    <thead><tr><th>Domain</th><th>Client</th><th>Expiry</th><th>Status</th></tr></thead>
    <tbody>
        @foreach($domains as $d)
            <tr @class(['danger'=>$d->isExpiringSoon()])>
                <td>{{ $d->name }}</td>
                <td>{{ optional($d->client)->business_name ?? '-' }}</td>
                <td>{{ optional($d->expiry_date)->toDateString() }}</td>
                <td>{{ $d->status }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
@endsection
