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
                        <tr class="service-row" data-service-id="{{ $service->id }}">
                            <td>
                                <div class="font-medium text-gray-900 dark:text-gray-100">
                                    {{ $domainLabel }}
                                </div>
                                @if($service->server)
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $service->server }}
                                    </div>
                                @endif
                            </td>
                            <td>
                                <span class="text-sm text-gray-700 dark:text-gray-300">
                                    {{ $clientLabel }}
                                </span>
                            </td>
                            <td>
                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                    {{ $service->plan ?? '-' }}
                                </span>
                            </td>
                            <td>
                                <code class="text-xs bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">
                                    {{ $service->username ?? '-' }}
                                </code>
                            </td>
                            <td>
                                @if($diskUsage !== null || $diskLimit !== null)
                                    <div class="text-sm">
                                        <span class="font-medium">{{ $diskUsage ?? '?' }}</span>
                                        <span class="text-gray-500 dark:text-gray-400">MB / </span>
                                        <span class="font-medium">{{ $diskLimit ?? '?' }}</span>
                                        <span class="text-gray-500 dark:text-gray-400">MB</span>
                                    </div>
                                    @if($diskUsage && $diskLimit)
                                        @php
                                            $percentage = ($diskUsage / $diskLimit) * 100;
                                            $colorClass = $percentage > 90 ? 'bg-red-500' : ($percentage > 70 ? 'bg-yellow-500' : 'bg-green-500');
                                        @endphp
                                        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5 mt-1">
                                            <div class="{{ $colorClass }} h-1.5 rounded-full" style="width: {{ min($percentage, 100) }}%"></div>
                                        </div>
                                    @endif
                                @else
                                    <span class="text-gray-400 dark:text-gray-500">-</span>
                                @endif
                            </td>
                            <td>
                                @if($service->service_status === 'Active')
                                    <span class="status-badge status-active">Active</span>
                                @elseif($service->is_suspended ?? false)
                                    <span class="status-badge status-suspended">Suspended</span>
                                @else
                                    <span class="status-badge status-inactive">{{ $service->service_status ?? 'Unknown' }}</span>
                                @endif
                            </td>
                            <td>
                                <button type="button" 
                                        class="btn btn-sm btn-secondary expand-btn"
                                        data-service-id="{{ $service->id }}">
                                    <svg class="w-4 h-4 expand-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                    Details
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

        @if($services->hasPages())
            <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
                {{ $services->links() }}
            </div>
        @endif
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
.status-badge {
    @apply inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium;
}
.status-active {
    @apply bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200;
}
.status-suspended {
    @apply bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200;
}
.status-inactive {
    @apply bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300;
}

.expand-icon {
    transition: transform 0.2s ease;
}
.expand-btn.expanded .expand-icon {
    transform: rotate(180deg);
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    
    // Toggle details rows
    document.querySelectorAll('.expand-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const serviceId = this.dataset.serviceId;
            const detailsRow = document.getElementById(`details-${serviceId}`);
            
            if (detailsRow.classList.contains('hidden')) {
                detailsRow.classList.remove('hidden');
                this.classList.add('expanded');
            } else {
                detailsRow.classList.add('hidden');
                this.classList.remove('expanded');
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