@extends('layouts.app')
@section('content')
<h1>Log Support Ticket</h1>
<form method="POST" action="{{ route('tickets.store') }}">
@csrf
<label>Subject</label><input type="text" name="subject" required><br>
<label>Message</label><textarea name="message" required></textarea><br>
<label>Type</label>
<select name="type" required>
    <option value="domain">Domain</option>
    <option value="service">Service</option>
</select><br>
<label>Reference</label>
<select name="reference_id" required>
    @foreach($domains as $d)
        <option value="domain:{{ $d->id }}">Domain: {{ $d->name }}</option>
    @endforeach
    @foreach($services as $s)
        <option value="service:{{ $s->id }}">Service: {{ $s->username }} ({{ optional($s->domain)->name }})</option>
    @endforeach
</select><br>
<label>Client</label>
<select name="client_id" required>
    @foreach(auth()->user()->clients as $c)
        <option value="{{ $c->id }}">{{ $c->business_name }}</option>
    @endforeach
</select><br>
<button type="submit">Submit</button>
</form>
@endsection
