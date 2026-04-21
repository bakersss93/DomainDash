@extends('layouts.app')

@section('content')
<div class="dd-page">
    <h1 class="dd-page-title">Dashboard</h1>

    <div class="dd-card" style="margin-bottom: 1rem;">
        <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input class="dd-input dd-input-inline" type="text" name="q" placeholder="Search services, status, or client" value="{{ $search }}">
            <button type="submit" class="btn-accent">Search</button>
        </form>
    </div>

    @if($domains->isEmpty() && $hostingServices->isEmpty() && $sslCertificates->isEmpty())
        <div class="dd-card">
            <p style="margin:0; color:#6b7280;">
                No assigned services found{{ $search !== '' ? ' for the current search.' : '.' }}
            </p>
        </div>
    @endif

    @if($domains->isNotEmpty())
        <div class="dd-card" style="margin-bottom: 1rem;">
            <h2 style="margin-bottom: 0.85rem;">Domains</h2>
            <table class="dd-table-clean">
                <thead>
                    <tr>
                        <th>Domain</th>
                        <th>Client</th>
                        <th>Expiry</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($domains as $domain)
                        <tr @class(['danger' => $domain->isExpiringSoon()])>
                            <td>{{ $domain->name }}</td>
                            <td>{{ optional($domain->client)->business_name ?? '-' }}</td>
                            <td>{{ optional($domain->expiry_date)->toDateString() ?? '-' }}</td>
                            <td>{{ $domain->status ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($hostingServices->isNotEmpty())
        <div class="dd-card" style="margin-bottom: 1rem;">
            <h2 style="margin-bottom: 0.85rem;">Hosting Services</h2>
            <table class="dd-table-clean">
                <thead>
                    <tr>
                        <th>Domain</th>
                        <th>Plan</th>
                        <th>Username</th>
                        <th>Client</th>
                        <th>Renewal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($hostingServices as $service)
                        <tr>
                            <td>{{ optional($service->domain)->name ?? '-' }}</td>
                            <td>{{ $service->plan ?? '-' }}</td>
                            <td>{{ $service->username ?? '-' }}</td>
                            <td>{{ optional($service->client)->business_name ?? '-' }}</td>
                            <td>{{ optional($service->next_renewal_due)->toDateString() ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($sslCertificates->isNotEmpty())
        <div class="dd-card">
            <h2 style="margin-bottom: 0.85rem;">SSL Certificates</h2>
            <table class="dd-table-clean">
                <thead>
                    <tr>
                        <th>Common Name</th>
                        <th>Product</th>
                        <th>Client</th>
                        <th>Expires</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($sslCertificates as $certificate)
                        <tr @class(['danger' => $certificate->isExpiringSoon()])>
                            <td>{{ $certificate->common_name ?? '-' }}</td>
                            <td>{{ $certificate->product_name ?? '-' }}</td>
                            <td>{{ optional($certificate->client)->business_name ?? '-' }}</td>
                            <td>{{ optional($certificate->expire_date)->toDateString() ?? '-' }}</td>
                            <td>{{ $certificate->status ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
