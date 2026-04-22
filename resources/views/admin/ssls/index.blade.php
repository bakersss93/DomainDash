@extends('layouts.app')
@section('content')
<h1>SSL Certificates</h1>
<form method="POST" action="{{ route('admin.services.ssl.sync') }}" style="margin-bottom:12px;">
    @csrf
    <button type="submit" class="btn-accent">Sync from Synergy</button>
</form>
<form method="GET">
    <select name="client_id">
        <option value="">All Clients</option>
        @foreach($clients as $c)
            <option value="{{ $c->id }}" @selected(request('client_id')==$c->id)>{{ $c->business_name }}</option>
        @endforeach
    </select>
    <button type="submit">Filter</button>
</form>
<table border="1" cellpadding="6" cellspacing="0" width="100%" style="margin-top:12px;">
    <thead><tr><th>Common Name</th><th>Client</th><th>Product</th><th>Start</th><th>Expire</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    @foreach($ssls as $s)
        <tr @class(['danger'=>$s->isExpiringSoon()])>
            <td>
                <a href="{{ route('admin.services.ssl.show', $s) }}" style="color:inherit;text-decoration:underline;">
                    {{ $s->common_name }}
                </a>
            </td>
            <td>{{ optional($s->client)->business_name }}</td>
            <td>{{ $s->product_name ?: 'Unknown product' }}</td>
            <td>{{ optional($s->start_date)->toDateString() }}</td>
            <td>{{ optional($s->expire_date)->toDateString() }}</td>
            <td>{{ $s->status }}</td>
            <td><a href="{{ route('admin.services.ssl.show', $s) }}">Manage</a></td>
        </tr>
    @endforeach
    </tbody>
</table>
{{ $ssls->withQueryString()->links() }}
@endsection
