@extends('layouts.app')

@section('content')
<div class="dd-page">
<div class="dd-card">
    <h1 class="dd-page-title" style="font-size:1.45rem;">
        Hosting Services
    </h1>

    {{-- Filter + sync toolbar --}}
    <div class="dd-services-toolbar">
        <form method="GET"
              action="{{ route('admin.services.hosting') }}"
              class="dd-services-filter">
            <select name="client_id"
                    class="dd-input dd-input-inline">
                <option value="">All clients</option>
                @foreach($clients as $client)
                    <option value="{{ $client->id }}"
                        {{ (isset($clientId) && (int)$clientId === $client->id) ? 'selected' : '' }}>
                        {{ $client->business_name ?? $client->name ?? ('Client #' . $client->id) }}
                    </option>
                @endforeach
            </select>

            <button type="submit" class="btn-accent dd-pill-btn">
                Filter
            </button>
        </form>

        <form method="POST"
              action="{{ route('admin.services.hosting.sync') }}"
              class="dd-services-sync">
            @csrf
            <button type="submit"
                    class="btn-accent dd-pill-btn"
                    onclick="return confirm('Sync hosting services from Synergy now?');">
                Sync services
            </button>
        </form>
    </div>

    {{-- Services table --}}
    <div class="dd-services-table-wrapper">
        <table class="dd-services-table">
            <thead>
            <tr>
                <th>Domain</th>
                <th>Plan</th>
                <th>Username</th>
                <th>Server</th>
                <th>Disk</th>
                <th>IP</th>
            </tr>
            </thead>
            <tbody>
            @forelse($services as $service)
                <tr>
                    <td>
                        {{ optional($service->domain)->name
                           ?? $service->domain_name
                           ?? '-' }}
                    </td>
                    <td>{{ $service->plan ?? '-' }}</td>
                    <td>{{ $service->username ?? '-' }}</td>
                    <td>{{ $service->server ?? '-' }}</td>
                    <td>
                        @php
                            $usage = $service->disk_usage ?? null;
                            $limit = $service->disk_limit ?? null;
                        @endphp

                        @if($usage !== null || $limit !== null)
                            {{ $usage ?? '?' }} / {{ $limit ?? '?' }}
                        @else
                            -
                        @endif
                    </td>
                    <td>
                        {{ $service->dedicated_ipv4
                           ?? $service->server_ip
                           ?? '-' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6"
                        style="text-align:center;padding:12px 0;opacity:.7;">
                        No hosting services found. Try syncing from Synergy.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="dd-services-pagination">
        {{ $services->links() }}
</div>
</div>
@endsection
