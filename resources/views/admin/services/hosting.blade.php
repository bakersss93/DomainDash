@extends('layouts.app')

@section('content')
<div class="dd-services-card">
    <h1 class="dd-services-title">
        Hosting Services
    </h1>

    {{-- Filter + sync toolbar --}}
    <div class="dd-services-toolbar">
        <form method="GET"
              action="{{ route('admin.services.hosting') }}"
              class="dd-services-filter">
            <select name="client_id"
                    class="dd-pill-input dd-pill-select">
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

<style>
    :root {
        --dd-card-radius: 18px;
        --dd-card-padding: 18px 20px;

        --dd-pill-radius: 9999px;
        --dd-pill-padding: 8px 14px;

        --dd-card-bg: #ffffff;
        --dd-card-border: #d1d5db;
        --dd-pill-bg: #f3f4f6;
        --dd-pill-border: #d1d5db;
        --dd-text-color: #111827;
        --dd-header-bg: #f9fafb;
        --dd-header-text: #111827;
        --dd-row-alt-bg: #f9fafb;
    }

    body.dark-mode,
    body[data-theme="dark"],
    html.dark,
    html[data-theme="dark"] {
        --dd-card-bg: #020617;
        --dd-card-border: #1f2937;
        --dd-pill-bg: #1f2937;
        --dd-pill-border: #4b5563;
        --dd-text-color: #e5e7eb;
        --dd-header-bg: #020617;
        --dd-header-text: #f9fafb;
        --dd-row-alt-bg: #111827;
    }

    .dd-services-card {
        border-radius: var(--dd-card-radius);
        padding: var(--dd-card-padding);
        margin-top: 24px;
        border: 1px solid var(--dd-card-border);
        background: var(--dd-card-bg);
        color: var(--dd-text-color);
    }

    .dd-services-title {
        font-size: 20px;
        font-weight: 600;
        margin-bottom: 12px;
    }

    .dd-services-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
        flex-wrap: wrap;
    }

    .dd-services-filter {
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;
    }

    .dd-services-sync {
        display: flex;
        align-items: center;
        justify-content: flex-end;
    }

    .dd-services-table-wrapper {
        overflow-x: auto;
    }

    .dd-services-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }

    .dd-services-table thead tr {
        background: var(--dd-header-bg);
        color: var(--dd-header-text);
    }

    .dd-services-table th,
    .dd-services-table td {
        padding: 8px 10px;
        text-align: left;
        border-bottom: 1px solid rgba(148,163,184,0.4);
    }

    .dd-services-table tbody tr:nth-child(even) {
        background: var(--dd-row-alt-bg);
    }

    .dd-services-pagination {
        margin-top: 10px;
    }

    .dd-pill-input {
        border-radius: var(--dd-pill-radius) !important;
        border: 1px solid var(--dd-pill-border) !important;
        padding: var(--dd-pill-padding) !important;
        font-size: 14px;
        outline: none;
        background: var(--dd-pill-bg) !important;
        color: var(--dd-text-color) !important;
    }

    .dd-pill-input:focus {
        border-color: var(--accent, #4ade80) !important;
    }

    .dd-pill-select {
        min-width: 200px;
    }

    .dd-pill-btn {
        border-radius: var(--dd-pill-radius) !important;
        padding: 8px 16px !important;
    }
</style>
@endsection
