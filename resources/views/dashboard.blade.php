@extends('layouts.app')
@section('content')
@php
$clientIds = auth()->user()->hasRole('Administrator') ? \App\Models\Client::pluck('id') : auth()->user()->clients()->pluck('clients.id');
$search = trim((string) request('q', ''));
$domainsQuery = \App\Models\Domain::whereIn('client_id',$clientIds);
if ($search !== '') {
    $domainsQuery->where(function ($query) use ($search) {
        $query->where('name', 'like', '%'.$search.'%')
            ->orWhere('status', 'like', '%'.$search.'%')
            ->orWhereHas('client', function ($clientQuery) use ($search) {
                $clientQuery->where('business_name', 'like', '%'.$search.'%');
            });
    });
}
$domains = $domainsQuery->with('client')->orderBy('name')->limit(50)->get();
@endphp

<div class="dd-page">
    <h1 class="dd-page-title">Dashboard</h1>
    <div class="dd-card">
        <div class="dd-toolbar">
            <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input class="dd-input dd-input-inline" type="text" name="q" placeholder="Search domains, status, or client" value="{{ $search }}">
                <button type="submit" class="btn-accent">Search</button>
            </form>
        </div>

        <table class="dd-table-clean">
            <thead><tr><th>Domain</th><th>Client</th><th>Expiry</th><th>Status</th></tr></thead>
            <tbody>
                @forelse($domains as $d)
                    <tr @class(['danger'=>$d->isExpiringSoon()])>
                        <td>{{ $d->name }}</td>
                        <td>{{ optional($d->client)->business_name ?? '-' }}</td>
                        <td>{{ optional($d->expiry_date)->toDateString() }}</td>
                        <td>{{ $d->status }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">No matching domains found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
