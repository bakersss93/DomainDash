@extends('layouts.app')

@section('content')
<div style="max-width: 1200px; margin: 0 auto;">
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

            <select name="status"
                    class="dd-pill-input dd-pill-select">
                <option value="">All statuses</option>
                <option value="ACTIVE" {{ (isset($statusFilter) && $statusFilter === 'ACTIVE') ? 'selected' : '' }}>Active</option>
                <option value="SUSPENDED" {{ (isset($statusFilter) && $statusFilter === 'SUSPENDED') ? 'selected' : '' }}>Suspended</option>
                <option value="TERMINATED" {{ (isset($statusFilter) && $statusFilter === 'TERMINATED') ? 'selected' : '' }}>Terminated</option>
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
                    class="btn btn-accent"
                    onclick="return confirm('Sync hosting services from Synergy now?');">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
                Sync from Synergy
            </button>
        </form>
    </div>

    {{-- Filter Card --}}
    <div class="card mb-6">
        <form method="GET" action="{{ route('admin.services.hosting') }}" class="flex items-center gap-4">
            <div class="flex-1">
                <label for="client_filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Filter by Client
                </label>
                <select name="client_id" 
                        id="client_filter"
                        class="input w-full">
                    <option value="">All clients</option>
                    @foreach($clients as $client)
                        <option value="{{ $client->id }}"
                            {{ (isset($clientId) && (int)$clientId === $client->id) ? 'selected' : '' }}>
                            {{ $client->business_name ?? $client->name ?? ('Client #' . $client->id) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="pt-7">
                <button type="submit" class="btn btn-primary">
                    Apply Filter
                </button>
            </div>
        </form>
    </div>

    {{-- Services Table Card --}}
    <div class="card">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Domain</th>
                        <th>Client</th>
                        <th>Plan</th>
                        <th>Username</th>
                        <th>Disk Usage</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($services as $service)
                        @php
                            $domainLabel = optional($service->domain)->name
                                ?? $service->domain_name
                                ?? $service->domain
                                ?? '-';
                            $clientLabel = optional($service->client)->business_name
                                ?? optional($service->client)->name
                                ?? 'Unassigned';
                            $diskUsage = $service->disk_usage_mb ?? null;
                            $diskLimit = $service->disk_limit_mb ?? null;
                        @endphp
                        @if($usage !== null || $limit !== null)
                            {{ $usage ?? '?' }} / {{ $limit ?? '?' }}
                        @else
                            -
                        @endif
                    </td>
                    <td>
                        {{ $service->ip
                           ?? $service->ip_address
                           ?? $service->dedicated_ipv4
                           ?? '-' }}
                    </td>
                </tr>

                {{-- Slide-down details row --}}
                <tr data-service-panel="service-{{ $service->id }}" class="dd-service-panel">
                    <td colspan="6">
                        <div class="dd-service-panel-inner" data-details-for="{{ $service->id }}">
                            {{-- Service info header --}}
                            <div class="dd-service-panel-header">
                                <div>
                                    <div style="font-weight:600;">{{ $domainLabel }}</div>
                                    <div style="font-size:13px;opacity:.8;">
                                        <span class="dd-detail-plan">{{ $service->plan ?? 'Plan unknown' }}</span>
                                        • Username: <span class="dd-detail-username">{{ $service->username ?? '—' }}</span>
                                    </div>
                                </div>
                                <div style="font-size:13px;opacity:.7;">
                                    Server: <span class="dd-detail-server">{{ $service->server ?? '—' }}</span>
                                </div>
                            </div>

                            {{-- Actions grid --}}
                            <div class="dd-service-options-grid">
                                {{-- Overview --}}
                                <a href="{{ route('admin.services.hosting.show', $service) }}" class="dd-service-option">
                                    <div class="dd-service-option-icon">👁️</div>
                                    <div class="dd-service-option-label">Overview</div>
                                </a>

                                {{-- Show password --}}
                                <button type="button"
                                        class="dd-service-option dd-password-btn"
                                        data-password-id="{{ $service->id }}">
                                    <div class="dd-service-option-icon">🔐</div>
                                    <div class="dd-service-option-label">Show password</div>
                                </button>
                            </td>
                        </tr>
                        
                        {{-- Expandable details row --}}
                        <tr class="details-row hidden" id="details-{{ $service->id }}">
                            <td colspan="7" class="bg-gray-50 dark:bg-gray-800/50">
                                <div class="p-6 space-y-6" data-details-for="{{ $service->id }}">
                                    {{-- Service Information Grid --}}
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                        <div>
                                            <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">Service Details</h4>
                                            <dl class="space-y-2">
                                                <div>
                                                    <dt class="text-xs text-gray-500 dark:text-gray-400">Plan</dt>
                                                    <dd class="text-sm font-medium detail-plan">{{ $service->plan ?? '-' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs text-gray-500 dark:text-gray-400">Server</dt>
                                                    <dd class="text-sm font-medium detail-server">{{ $service->server ?? '-' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs text-gray-500 dark:text-gray-400">Status</dt>
                                                    <dd class="text-sm font-medium detail-status">{{ $service->service_status ?? '-' }}</dd>
                                                </div>
                                            </dl>
                                        </div>

                                        <div>
                                            <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">Access Details</h4>
                                            <dl class="space-y-2">
                                                <div>
                                                    <dt class="text-xs text-gray-500 dark:text-gray-400">Username</dt>
                                                    <dd class="text-sm font-medium detail-username">{{ $service->username ?? '-' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs text-gray-500 dark:text-gray-400">IP Address</dt>
                                                    <dd class="text-sm font-medium detail-ip">{{ $service->ip ?? $service->ip_address ?? '-' }}</dd>
                                                </div>
                                                <div>
                                                    <dt class="text-xs text-gray-500 dark:text-gray-400">Password</dt>
                                                    <dd>
                                                        <button type="button"
                                                                class="btn btn-sm btn-secondary password-btn"
                                                                data-service-id="{{ $service->id }}">
                                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                            </svg>
                                                            Show Password
                                                        </button>
                                                    </dd>
                                                </div>
                                            </dl>
                                        </div>

                                        <div>
                                            <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">Resource Usage</h4>
                                            <dl class="space-y-2">
                                                <div>
                                                    <dt class="text-xs text-gray-500 dark:text-gray-400">Disk Usage</dt>
                                                    <dd class="text-sm font-medium detail-disk">
                                                        @if($diskUsage !== null || $diskLimit !== null)
                                                            {{ $diskUsage ?? '?' }} / {{ $diskLimit ?? '?' }} MB
                                                        @else
                                                            -
                                                        @endif
                                                    </dd>
                                                </div>
                                            </dl>
                                        </div>
                                    </div>

                                    {{-- Action Buttons --}}
                                    <div class="flex flex-wrap gap-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                                        <form method="POST" 
                                              action="{{ route('admin.services.hosting.login', $service) }}"
                                              target="_blank"
                                              class="inline">
                                            @csrf
                                            <button type="submit" class="btn btn-primary">
                                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                                </svg>
                                                Open cPanel
                                            </button>
                                        </form>

                                        <button type="button"
                                                class="btn btn-secondary assign-client-btn"
                                                data-service-id="{{ $service->id }}"
                                                data-current-client="{{ $service->client_id ?? '' }}">
                                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                            </svg>
                                            Assign Client
                                        </button>

                                        <button type="button"
                                                class="btn btn-secondary change-domain-btn"
                                                data-service-id="{{ $service->id }}"
                                                data-domain="{{ $service->domain_name ?? '' }}">
                                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path>
                                            </svg>
                                            Change Domain
                                        </button>

                                        <form method="POST" 
                                              action="{{ route('admin.services.hosting.suspend', $service) }}"
                                              class="inline">
                                            @csrf
                                            <button type="submit" 
                                                    class="btn btn-danger"
                                                    onclick="return confirm('Are you sure you want to {{ ($service->is_suspended ?? false) ? 'unsuspend' : 'suspend' }} this service?');">
                                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    @if($service->is_suspended ?? false)
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                    @else
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                    @endif
                                                </svg>
                                                {{ ($service->is_suspended ?? false) ? 'Unsuspend' : 'Suspend' }}
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-12 text-gray-500 dark:text-gray-400">
                                <svg class="w-16 h-16 mx-auto mb-4 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path>
                                </svg>
                                <p class="text-lg font-medium mb-2">No hosting services found</p>
                                <p class="text-sm">Try syncing from Synergy to import services</p>
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
</div>

{{-- Password Modal --}}
<div id="password-modal" class="modal" aria-hidden="true">
    <div class="modal-backdrop"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Service Password</h3>
            <button type="button" class="modal-close" data-close-modal="password">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <div class="relative">
                <input type="password" 
                       id="password-field"
                       class="input pr-20" 
                       readonly
                       value="••••••••">
                <button type="button" 
                        id="toggle-password"
                        class="absolute right-2 top-1/2 -translate-y-1/2 px-3 py-1 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                </button>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-close-modal="password">
                Close
            </button>
            <button type="button" id="copy-password" class="btn btn-primary">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                </svg>
                Copy to Clipboard
            </button>
        </div>
    </div>
</div>

{{-- Assign Client Modal --}}
<div id="assign-modal" class="modal" aria-hidden="true">
    <div class="modal-backdrop"></div>
    <div class="modal-content">
        <form id="assign-form" method="POST">
            @csrf
            <div class="modal-header">
                <h3 class="modal-title">Assign Client</h3>
                <button type="button" class="modal-close" data-close-modal="assign">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <label for="assign-client-select" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Select Client
                </label>
                <select name="client_id" 
                        id="assign-client-select"
                        class="input">
                    <option value="">Unassigned</option>
                    @foreach($clients as $client)
                        <option value="{{ $client->id }}">
                            {{ $client->business_name ?? $client->name ?? ('Client #' . $client->id) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-close-modal="assign">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    Save
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Change Domain Modal --}}
<div id="domain-modal" class="modal" aria-hidden="true">
    <div class="modal-backdrop"></div>
    <div class="modal-content">
        <form id="domain-form" method="POST">
            @csrf
            <div class="modal-header">
                <h3 class="modal-title">Change Primary Domain</h3>
                <button type="button" class="modal-close" data-close-modal="domain">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <label for="domain-input" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    New Domain Name
                </label>
                <input type="text" 
                       name="domain_name" 
                       id="domain-input"
                       class="input"
                       placeholder="example.com"
                       required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-close-modal="domain">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    Update
                </button>
            </div>
        </form>
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
        --dd-row-alt-bg: #f9f9fb;
        --dd-hover-bg: rgba(148,163,184,0.12);
        --dd-overlay-bg: rgba(15,23,42,0.65);
    }

    body.dark-mode,
    body[data-theme="dark"],
    html.dark,
    html[data-theme="dark"] {
        --dd-card-bg: #020617;
        --dd-card-border: #1f2937;
        --dd-pill-bg: #0f172a;
        --dd-pill-border: #374151;
        --dd-text-color: #e5e7eb;
        --dd-header-bg: #020617;
        --dd-header-text: #f9fafb;
        --dd-row-alt-bg: #111827;
        --dd-hover-bg: rgba(148,163,184,0.18);
        --dd-overlay-bg: rgba(15,23,42,0.85);
    }

    .dd-hidden {
        display: none;
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

    .dd-service-row {
        cursor: pointer;
    }

    .dd-service-row:hover {
        background-color: rgba(148,163,184,0.18);
    }

    .dd-service-domain-cell {
        position: relative;
    }

    .dd-service-domain-text {
        text-decoration: none;
    }

    /* Expandable panel with smooth transitions */
    tr[data-service-panel] {
        display: none;
        height: 0;
    }
    tr[data-service-panel] > td {
        padding: 0;
        border: 0;
    }
    tr[data-service-panel].open {
        display: table-row;
        height: auto;
    }

    .dd-service-panel-inner {
        max-height: 0;
        padding: 0;
        margin-top: 0;
        border: 0;
        overflow: hidden;
        opacity: 0;
        transform: translateY(-4px);
        transition:
            max-height 0.25s ease,
            opacity 0.2s ease,
            transform 0.2s ease,
            padding 0.2s ease,
            margin-top 0.2s ease,
            border-width 0.2s ease;
    }

    tr[data-service-panel].open > td > .dd-service-panel-inner {
        max-height: 600px;
        opacity: 1;
        transform: translateY(0);
        padding: 16px 18px 18px;
        margin-top: 0;
        border-radius: 8px;
        border: 1px solid var(--dd-card-border);
        background: var(--dd-card-bg);
    }

    .dd-service-panel-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 12px;
        border-radius: 6px;
        background: var(--dd-header-bg);
        border: 1px solid var(--dd-card-border);
        margin-bottom: 14px;
    }

    .dd-service-options-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 10px;
    }

    .dd-service-option,
    .dd-service-option-btn {
        display: flex;
        align-items: center;
        padding: 10px 14px;
        border-radius: 9999px;
        background: var(--dd-pill-bg);
        border: 1px solid var(--dd-pill-border);
        text-decoration: none;
        font-size: 14px;
        cursor: pointer;
        width: 100%;
        color: var(--dd-text-color);
        transition: background 0.15s ease, transform 0.15s ease, border-color 0.15s ease;
    }

    .dd-service-option:hover,
    .dd-service-option-btn:hover {
        background: var(--dd-hover-bg, rgba(148,163,184,0.18));
        border-color: var(--accent);
        transform: translateY(-1px);
    }

    .dd-service-option-btn {
        background: transparent;
        color: inherit;
    }

    .dd-service-option-icon {
        width: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 8px;
        font-size: 16px;
    }

    .dd-service-option-label {
        flex: 1;
        text-align: left;
    }

    .dd-service-option-danger {
        border-color: #ef4444;
    }

    .dd-service-option-danger:hover {
        background: rgba(239, 68, 68, 0.1);
        border-color: #dc2626;
    }

    .dd-service-form {
        border-radius: 12px;
        padding: 10px;
        border: 1px solid var(--dd-card-border);
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .dd-service-form-label {
        font-size: 12px;
        font-weight: 500;
        margin-bottom: 2px;
        display: block;
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

    /* Password modal */
    .dd-password-modal {
        position: fixed;
        inset: 0;
        z-index: 999;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .dd-password-backdrop {
        position: absolute;
        inset: 0;
        background: var(--dd-overlay-bg);
    }

    .dd-password-panel {
        position: relative;
        z-index: 1000;
        max-width: 420px;
        width: 100%;
        border-radius: var(--dd-card-radius);
        padding: 20px;
        background: var(--dd-card-bg);
        border: 1px solid var(--dd-card-border);
    }

    .dd-password-title {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 10px;
    }

    .dd-password-input-wrapper {
        position: relative;
        margin-bottom: 10px;
    }

    .dd-password-input {
        width: 100%;
        padding-right: 44px !important;
    }

    .dd-password-toggle {
        position: absolute;
        right: 8px;
        top: 50%;
        transform: translateY(-50%);
        border: none;
        background: transparent;
        cursor: pointer;
        font-size: 16px;
    }

    .dd-password-actions {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
    }

.expand-icon {
    transition: transform 0.2s ease;
}
.expand-btn.expanded .expand-icon {
    transform: rotate(180deg);
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Modal elements (declared at top level so they're accessible throughout)
    const assignModal  = document.getElementById('dd-assign-modal');
    const assignForm   = document.getElementById('dd-assign-form');
    const assignSelect = document.getElementById('dd-assign-select');
    const domainModal  = document.getElementById('dd-domain-modal');
    const domainForm   = document.getElementById('dd-domain-form');
    const domainInput  = document.getElementById('dd-domain-input');

    // Slide-down details toggles (click anywhere on row except controls)
    document.querySelectorAll('.dd-service-row').forEach(function (row) {
        const id = row.getAttribute('data-service-id');
        const panelId = 'service-' + id;
        const detailsRow = document.querySelector('[data-service-panel="' + panelId + '"]');
        const detailsWrapper = detailsRow
            ? detailsRow.querySelector('[data-details-for="' + id + '"]')
            : null;

        if (!detailsRow || !detailsWrapper) return;

        let loaded = false;

        function loadDetails() {
            if (loaded) return;

            fetch('{{ url('admin/services/hosting') }}/' + id + '/details', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(r => r.json())
            .then(data => {
                loaded = true;

                // Update header info
                const planSpan = detailsWrapper.querySelector('.dd-detail-plan');
                if (planSpan) planSpan.textContent = data.plan || 'Plan unknown';

                const serverSpan = detailsWrapper.querySelector('.dd-detail-server');
                if (serverSpan) serverSpan.textContent = data.server || '—';

                const usernameSpan = detailsWrapper.querySelector('.dd-detail-username');
                if (usernameSpan) usernameSpan.textContent = data.username || '—';
            })
            .catch(() => { /* ignore */ });
        }

        row.addEventListener('click', function (e) {
            // Don't toggle when clicking buttons, links, inputs, selects etc.
            if (e.target.closest('button, form, a, select, input, label')) {
                return;
            }

            const isOpen = detailsRow.classList.contains('open');
            if (!isOpen) {
                loadDetails();
                detailsRow.classList.add('open');
            } else {
                detailsRow.classList.remove('open');
            }
        });
    });
    
    // Password modal
    const passwordModal = document.getElementById('password-modal');
    const passwordField = document.getElementById('password-field');
    const togglePassword = document.getElementById('toggle-password');
    const copyPassword = document.getElementById('copy-password');
    
    document.querySelectorAll('.password-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const serviceId = this.dataset.serviceId;
            
            try {
                const response = await fetch(`/admin/services/hosting/${serviceId}/password`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });
                
                const data = await response.json();
                
                if (data.ok) {
                    passwordField.value = data.password;
                    passwordField.type = 'password';
                    openModal('password');
                } else {
                    alert(data.message || 'Failed to fetch password');
                }
            } catch (error) {
                alert('Error fetching password');
            }
        });
    });
    
    if (togglePassword) {
        togglePassword.addEventListener('click', () => {
            passwordField.type = passwordField.type === 'password' ? 'text' : 'password';
        });
    }
    
    if (copyPassword) {
        copyPassword.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(passwordField.value);
                const originalText = copyPassword.innerHTML;
                copyPassword.innerHTML = '✓ Copied!';
                setTimeout(() => copyPassword.innerHTML = originalText, 2000);
            } catch (error) {
                // Fallback
                passwordField.select();
                document.execCommand('copy');
            }
        });
    }
    
    // Assign client modal
    const assignModal = document.getElementById('assign-modal');
    const assignForm = document.getElementById('assign-form');
    const assignSelect = document.getElementById('assign-client-select');
    
    document.querySelectorAll('.assign-client-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const serviceId = this.dataset.serviceId;
            const currentClient = this.dataset.currentClient;
            
            assignForm.action = `/admin/services/hosting/${serviceId}/assign-client`;
            assignSelect.value = currentClient || '';
            openModal('assign');
        });
    });
    
    // Change domain modal
    const domainModal = document.getElementById('domain-modal');
    const domainForm = document.getElementById('domain-form');
    const domainInput = document.getElementById('domain-input');
    
    document.querySelectorAll('.change-domain-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const serviceId = this.dataset.serviceId;
            const currentDomain = this.dataset.domain;
            
            domainForm.action = `/admin/services/hosting/${serviceId}/change-domain`;
            domainInput.value = currentDomain || '';
            openModal('domain');
        });
    });
    
    // Modal helpers
    function openModal(name) {
        const modal = document.getElementById(`${name}-modal`);
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
    }
    
    function closeModal(name) {
        const modal = document.getElementById(`${name}-modal`);
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
    }
    
    document.querySelectorAll('[data-close-modal]').forEach(btn => {
        btn.addEventListener('click', function() {
            closeModal(this.dataset.closeModal);
        });
    });
    
    document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
        backdrop.addEventListener('click', function() {
            const modal = this.closest('.modal');
            modal.classList.add('hidden');
            modal.setAttribute('aria-hidden', 'true');
        });
    });
    
    // ESC to close modals
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal:not(.hidden)').forEach(modal => {
                modal.classList.add('hidden');
                modal.setAttribute('aria-hidden', 'true');
            });
        }
    });
});
</script>
@endsection