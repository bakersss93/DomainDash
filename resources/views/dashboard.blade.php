@extends('layouts.app')

@section('content')
<div class="ui-page">
    <x-ui.page-header title="Dashboard" subtitle="Domains, hosting and SSL certificates assigned to you." />

    <form method="GET" class="ui-toolbar" role="search">
        <label for="dashboard-search" class="sr-only">Search services</label>
        <input id="dashboard-search" class="dd-input" type="search" name="q" placeholder="Search services, status, or client" value="{{ $search }}" style="flex:1;min-width:14rem;max-width:32rem;">
        <button type="submit" class="ui-btn ui-btn--primary">Search</button>
    </form>

    @php
        $expiringDomains = $domains->filter(fn ($d) => $d->isExpiringSoon())->count();
        $expiringSsl = $sslCertificates->filter(fn ($c) => $c->isExpiringSoon())->count();
    @endphp

    @if(! $domains->isEmpty() || ! $hostingServices->isEmpty() || ! $sslCertificates->isEmpty())
        <div class="ui-stat-grid" style="margin-bottom:1.5rem;">
            <x-ui.stat label="Domains" :value="$domains->count()" :hint="$expiringDomains.' expiring soon'" :variant="$expiringDomains ? 'warning' : null" />
            <x-ui.stat label="Hosting services" :value="$hostingServices->count()" />
            <x-ui.stat label="SSL certificates" :value="$sslCertificates->count()" :hint="$expiringSsl.' expiring soon'" :variant="$expiringSsl ? 'warning' : null" />
        </div>
    @endif

    @if($domains->isEmpty() && $hostingServices->isEmpty() && $sslCertificates->isEmpty())
        <x-ui.card>
            <x-ui.empty-state
                title="No services found"
                :text="'No assigned services found'.($search !== '' ? ' for the current search.' : '.')" />
        </x-ui.card>
    @endif

    <div style="display:grid;gap:1.5rem;">
        @if($domains->isNotEmpty())
            <x-ui.card title="Domains" :flush="true">
                <x-ui.table>
                    <thead>
                        <tr>
                            <th scope="col">Domain</th>
                            <th scope="col">Client</th>
                            <th scope="col">Expiry</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($domains as $domain)
                            <tr @class(['danger' => $domain->isExpiringSoon()])>
                                <td class="font-medium">{{ $domain->name }}</td>
                                <td>{{ optional($domain->client)->business_name ?? '-' }}</td>
                                <td>{{ optional($domain->expiry_date)->toDateString() ?? '-' }}</td>
                                <td><x-ui.badge :status="$domain->status">{{ $domain->status ?? '-' }}</x-ui.badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </x-ui.card>
        @endif

        @if($hostingServices->isNotEmpty())
            <x-ui.card title="Hosting Services" :flush="true">
                <x-ui.table>
                    <thead>
                        <tr>
                            <th scope="col">Domain</th>
                            <th scope="col">Plan</th>
                            <th scope="col">Username</th>
                            <th scope="col">Client</th>
                            <th scope="col">Renewal</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($hostingServices as $service)
                            <tr>
                                <td class="font-medium">{{ optional($service->domain)->name ?? '-' }}</td>
                                <td>{{ $service->plan ?? '-' }}</td>
                                <td>{{ $service->username ?? '-' }}</td>
                                <td>{{ optional($service->client)->business_name ?? '-' }}</td>
                                <td>{{ optional($service->next_renewal_due)->toDateString() ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </x-ui.card>
        @endif

        @if($sslCertificates->isNotEmpty())
            <x-ui.card title="SSL Certificates" :flush="true">
                <x-ui.table>
                    <thead>
                        <tr>
                            <th scope="col">Common Name</th>
                            <th scope="col">Product</th>
                            <th scope="col">Client</th>
                            <th scope="col">Expires</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sslCertificates as $certificate)
                            <tr @class(['danger' => $certificate->isExpiringSoon()])>
                                <td class="font-medium">{{ $certificate->common_name ?? '-' }}</td>
                                <td>{{ $certificate->product_name ?? '-' }}</td>
                                <td>{{ optional($certificate->client)->business_name ?? '-' }}</td>
                                <td>{{ optional($certificate->expire_date)->toDateString() ?? '-' }}</td>
                                <td><x-ui.badge :status="$certificate->status">{{ $certificate->status ?? '-' }}</x-ui.badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </x-ui.card>
        @endif
    </div>
</div>
@endsection
