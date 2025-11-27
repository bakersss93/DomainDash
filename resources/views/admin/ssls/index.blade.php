@extends('layouts.app')
@section('content')
<h1>SSL Certificates</h1>
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
    <thead><tr><th>Common Name</th><th>Client</th><th>Product</th><th>Start</th><th>Expire</th><th>Status</th></tr></thead>
    <tbody>
    @foreach($ssls as $s)
        <tr @class(['danger'=>$s->isExpiringSoon()])>
            <td>{{ $s->common_name }}</td>
            <td>{{ optional($s->client)->business_name }}</td>
            <td>{{ $s->product_name }}</td>
            <td>{{ optional($s->start_date)->toDateString() }}</td>
            <td>{{ optional($s->expire_date)->toDateString() }}</td>
            <td>{{ $s->status }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
{{ $ssls->withQueryString()->links() }}
@endsection
