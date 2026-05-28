@extends('layouts.app')

@section('content')

<style>
    /* ── Page ── */
    .ak-card {
        background: var(--surface-elevated);
        border: 1px solid var(--border-subtle);
        border-radius: 12px;
        padding: 20px 24px;
    }

    /* ── Shared modal overlay ── */
    .ak-overlay {
        position: fixed;
        inset: 0;
        background: rgba(2, 6, 23, 0.65);
        backdrop-filter: blur(2px);
        z-index: 60;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .ak-overlay.is-open { display: flex; }

    /* ── Modal dialog shell ── */
    .ak-dialog {
        width: min(94vw, 520px);
        background: var(--surface-elevated);
        border: 1px solid var(--border-subtle);
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 24px 48px rgba(0, 0, 0, 0.55);
        display: flex;
        flex-direction: column;
        max-height: 90vh;
    }
    .ak-dialog-sm { width: min(94vw, 460px); }

    .ak-dialog-header {
        padding: 16px 20px;
        border-bottom: 1px solid var(--border-subtle);
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-shrink: 0;
    }
    .ak-dialog-title {
        font-size: 16px;
        font-weight: 600;
        color: var(--text);
        margin: 0;
    }
    .ak-dialog-close {
        background: none;
        border: none;
        cursor: pointer;
        color: var(--text-muted);
        font-size: 20px;
        line-height: 1;
        padding: 2px 4px;
        border-radius: 4px;
        transition: color 0.15s, background 0.15s;
    }
    .ak-dialog-close:hover { color: var(--text); background: var(--surface-muted); }

    .ak-dialog-body {
        padding: 18px 20px;
        overflow-y: auto;
        flex: 1;
    }
    .ak-dialog-footer {
        padding: 12px 20px 16px;
        border-top: 1px solid var(--border-subtle);
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        flex-shrink: 0;
    }

    /* ── Form controls ── */
    .ak-field { margin-bottom: 14px; }

    .ak-label {
        display: block;
        font-size: 13px;
        font-weight: 500;
        color: var(--text-muted);
        margin-bottom: 5px;
    }
    .ak-label span { font-weight: 400; opacity: 0.75; }

    .ak-input {
        width: 100%;
        padding: 9px 12px;
        border-radius: 8px;
        border: 1px solid var(--border-subtle);
        background: var(--surface-muted);
        color: var(--text);
        font-size: 14px;
        box-sizing: border-box;
        outline: none;
        transition: border-color 0.15s;
    }
    .ak-input:focus { border-color: var(--accent); }
    .ak-input[readonly] { cursor: default; opacity: 0.9; }

    .ak-hint { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
    .ak-error { font-size: 12px; color: var(--danger-text, #f87171); margin-top: 3px; }

    /* ── Scope grid ── */
    .ak-scope-group { margin-bottom: 10px; }
    .ak-scope-group:last-child { margin-bottom: 0; }
    .ak-scope-group-label {
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.07em;
        text-transform: uppercase;
        color: var(--text-muted);
        margin-bottom: 5px;
    }
    .ak-scope-pills { display: flex; flex-wrap: wrap; gap: 6px; }
    .ak-scope-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 11px;
        border-radius: 9999px;
        cursor: pointer;
        border: 1px solid var(--border-subtle);
        background: var(--surface-muted);
        font-size: 13px;
        color: var(--text);
        transition: border-color 0.15s, background 0.15s;
        user-select: none;
    }
    .ak-scope-pill input[type="checkbox"] {
        accent-color: var(--accent);
        width: 13px;
        height: 13px;
        cursor: pointer;
        flex-shrink: 0;
    }
    .ak-scope-pill:has(input:checked) {
        border-color: var(--accent);
        background: color-mix(in srgb, var(--accent) 12%, var(--surface-muted) 88%);
    }
    .ak-scope-read  { color: #93c5fd; }
    .ak-scope-write { color: #fbbf24; }

    /* ── Buttons ── */
    .ak-btn-secondary {
        padding: 8px 16px;
        border-radius: 8px;
        border: 1px solid var(--border-subtle);
        background: transparent;
        color: var(--text);
        font-size: 14px;
        cursor: pointer;
        transition: background 0.15s, border-color 0.15s;
    }
    .ak-btn-secondary:hover {
        background: var(--surface-muted);
        border-color: color-mix(in srgb, var(--border-subtle) 60%, var(--text) 40%);
    }

    /* ── Inline action icon buttons ── */
    .ak-action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        padding: 0;
        border-radius: 6px;
        border: 1px solid var(--border-subtle);
        background: transparent;
        color: var(--text);
        cursor: pointer;
        flex-shrink: 0;
        transition: background 0.15s, border-color 0.15s, color 0.15s;
    }
    .ak-action-btn:hover { background: var(--surface-muted); }

    .ak-action-btn-warning {
        color: var(--warning-text, #78350f);
        border-color: var(--warning-border, #fcd34d);
        background: var(--warning-bg, #fef3c7);
    }
    html.dark .ak-action-btn-warning {
        color: #fde68a;
        border-color: rgba(245,158,11,0.45);
        background: rgba(245,158,11,0.1);
    }
    .ak-action-btn-warning:hover { filter: brightness(1.1); }

    .ak-action-btn-success {
        color: var(--success-text, #14532d);
        border-color: var(--success-border, #86efac);
        background: var(--success-bg, #dcfce7);
    }
    html.dark .ak-action-btn-success {
        color: #d1fae5;
        border-color: rgba(16,185,129,0.45);
        background: rgba(16,185,129,0.12);
    }
    .ak-action-btn-success:hover { filter: brightness(1.1); }

    .ak-action-btn-danger {
        color: var(--danger-text, #dc2626);
        border-color: color-mix(in srgb, var(--danger-text, #dc2626) 40%, transparent);
        background: color-mix(in srgb, var(--danger-text, #dc2626) 8%, transparent);
    }
    .ak-action-btn-danger:hover { filter: brightness(1.1); }

    /* ── Status badges ── */
    .ak-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 9px;
        border-radius: 9999px;
        font-size: 12px;
        font-weight: 500;
        white-space: nowrap;
    }
    .ak-badge::before {
        content: '';
        width: 6px;
        height: 6px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    .ak-badge-active {
        color: var(--success-text, #14532d);
        background: var(--success-bg, #dcfce7);
        border: 1px solid var(--success-border, #86efac);
    }
    html.dark .ak-badge-active {
        color: #d1fae5;
        background: rgba(16,185,129,0.12);
        border-color: rgba(16,185,129,0.4);
    }
    .ak-badge-active::before { background: currentColor; }

    .ak-badge-inactive {
        color: var(--text-muted);
        background: var(--surface-muted);
        border: 1px solid var(--border-subtle);
    }
    .ak-badge-inactive::before { background: var(--text-muted); opacity: 0.5; }

    /* ── Warning notice in reveal modal ── */
    .ak-warn {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        padding: 10px 12px;
        border-radius: 8px;
        border: 1px solid var(--warning-border, #fcd34d);
        background: var(--warning-bg, #fef3c7);
        color: var(--warning-text, #78350f);
        font-size: 13px;
        margin-bottom: 16px;
        line-height: 1.45;
    }
    html.dark .ak-warn {
        border-color: rgba(245,158,11,0.45);
        background: rgba(245,158,11,0.12);
        color: #fde68a;
    }

    /* ── Key copy row ── */
    .ak-key-row { display: flex; gap: 8px; align-items: stretch; }
    .ak-key-row .ak-input {
        font-family: ui-monospace, "Fira Code", monospace;
        font-size: 13px;
        letter-spacing: 0.02em;
    }

    /* ── Table ── */
    .ak-table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .ak-table th {
        text-align: left;
        padding: 8px 8px;
        border-bottom: 1px solid var(--border-subtle);
        font-size: 12px;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .ak-table th:last-child { text-align: right; }
    .ak-table td {
        padding: 10px 8px;
        border-bottom: 1px solid var(--border-subtle);
        color: var(--text);
        vertical-align: middle;
    }
    .ak-table td:last-child { text-align: right; }
    .ak-table tbody tr:last-child td { border-bottom: none; }
    .ak-muted { color: var(--text-muted); font-size: 13px; }
</style>

<div style="max-width:960px;margin:0 auto;">

    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
        <h1 style="font-size:18px;font-weight:600;color:var(--text);margin:0;">API Keys</h1>
        <button id="btn-new-key" class="btn-accent" style="padding:8px 16px;">
            New API key
        </button>
    </div>

    @if(session('status'))
        <div style="margin-bottom:14px;padding:10px 14px;border-radius:8px;
                    background:var(--success-bg);border:1px solid var(--success-border);
                    color:var(--success-text);font-size:13px;">
            {{ session('status') }}
        </div>
    @endif

    {{-- Existing keys card --}}
    <div class="ak-card">
        <h2 style="font-size:15px;font-weight:600;color:var(--text);margin:0 0 14px;">Existing API keys</h2>

        <table class="ak-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Allowed IPs</th>
                    <th>Rate limit</th>
                    <th>Scopes</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($keys as $key)
                @php
                    $scopes = $key->scopes ?? [];
                    $isActive = (bool) $key->active;
                @endphp
                <tr>
                    <td style="font-weight:500;">{{ $key->name }}</td>

                    <td>
                        @if($isActive)
                            <span class="ak-badge ak-badge-active">Active</span>
                        @else
                            <span class="ak-badge ak-badge-inactive">Inactive</span>
                        @endif
                    </td>

                    <td class="ak-muted">{{ $key->allowed_ips ?: 'Any' }}</td>

                    <td class="ak-muted">{{ $key->rate_limit_per_hour ?? '—' }}&thinsp;/&thinsp;hr</td>

                    <td class="ak-muted" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="{{ $scopes ? implode(', ', $scopes) : 'All' }}">
                        {{ $scopes ? implode(', ', $scopes) : 'All' }}
                    </td>

                    <td class="ak-muted">{{ $key->created_at->diffForHumans() }}</td>

                    <td>
                        <div style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;">

                            {{-- Regenerate --}}
                            <form method="POST" action="{{ route('admin.apikeys.regenerate', $key) }}"
                                  onsubmit="return confirm('Regenerate this key? The current key will stop working immediately.');"
                                  style="display:inline;">
                                @csrf
                                <button type="submit" class="ak-action-btn" title="Regenerate key">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="23 4 23 10 17 10"/>
                                        <polyline points="1 20 1 14 7 14"/>
                                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                                    </svg>
                                </button>
                            </form>

                            {{-- Edit --}}
                            <button type="button"
                                    class="ak-action-btn ak-edit-btn"
                                    title="Edit key"
                                    data-key-id="{{ $key->id }}"
                                    data-key-name="{{ $key->name }}"
                                    data-allowed-ips="{{ $key->allowed_ips }}"
                                    data-rate-limit="{{ $key->rate_limit_per_hour }}"
                                    data-scopes="{{ json_encode($scopes) }}"
                                    data-update-url="{{ route('admin.apikeys.update', $key) }}">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                </svg>
                            </button>

                            {{-- Deactivate / Reactivate --}}
                            @if($isActive)
                                <form method="POST" action="{{ route('admin.apikeys.deactivate', $key) }}"
                                      onsubmit="return confirm('Deactivate this API key? It will stop working immediately.');"
                                      style="display:inline;">
                                    @csrf
                                    <button type="submit" class="ak-action-btn ak-action-btn-warning" title="Deactivate key">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="10"/>
                                            <line x1="10" y1="15" x2="10" y2="9"/>
                                            <line x1="14" y1="15" x2="14" y2="9"/>
                                        </svg>
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('admin.apikeys.reactivate', $key) }}"
                                      style="display:inline;">
                                    @csrf
                                    <button type="submit" class="ak-action-btn ak-action-btn-success" title="Reactivate key">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="10"/>
                                            <polygon points="10 8 16 12 10 16 10 8"/>
                                        </svg>
                                    </button>
                                </form>
                            @endif

                            {{-- Delete --}}
                            <form method="POST" action="{{ route('admin.apikeys.destroy', $key) }}"
                                  onsubmit="return confirm('Permanently delete this API key? This cannot be undone.');"
                                  style="display:inline;">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ak-action-btn ak-action-btn-danger" title="Delete key">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="3 6 5 6 21 6"/>
                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                    </svg>
                                </button>
                            </form>

                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="padding:20px 8px;text-align:center;color:var(--text-muted);">
                        No API keys created yet.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

</div>

{{-- ══════════════════════════════════════════════════════════
     Create key modal
════════════════════════════════════════════════════════════ --}}
<div id="modal-create" class="ak-overlay" role="dialog" aria-modal="true" aria-labelledby="modal-create-title">
    <div class="ak-dialog">

        <div class="ak-dialog-header">
            <h2 id="modal-create-title" class="ak-dialog-title">New API key</h2>
            <button class="ak-dialog-close" id="modal-create-close" aria-label="Close">&times;</button>
        </div>

        <div class="ak-dialog-body">
            <form method="POST" action="{{ route('admin.apikeys.store') }}" id="form-create-key">
                @csrf

                <div class="ak-field">
                    <label class="ak-label" for="name">Name</label>
                    <input id="name" class="ak-input" name="name" type="text"
                           value="{{ old('name') }}"
                           placeholder="e.g. Monitoring integration">
                    @error('name')
                        <p class="ak-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="ak-field">
                    <label class="ak-label" for="allowed_ips">
                        Allowed IPs <span>(optional)</span>
                    </label>
                    <input id="allowed_ips" class="ak-input" name="allowed_ips" type="text"
                           value="{{ old('allowed_ips') }}"
                           placeholder="Comma-separated — e.g. 1.2.3.4, 10.0.0.0/8">
                    <p class="ak-hint">Leave blank to allow requests from any IP address.</p>
                    @error('allowed_ips')
                        <p class="ak-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="ak-field">
                    <label class="ak-label" for="rate_limit_per_hour">Rate limit per hour</label>
                    <input id="rate_limit_per_hour" class="ak-input" name="rate_limit_per_hour"
                           type="number" min="1"
                           value="{{ old('rate_limit_per_hour', 1000) }}">
                    @error('rate_limit_per_hour')
                        <p class="ak-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="ak-field" style="margin-bottom:0;">
                    <label class="ak-label">Scopes <span>(optional — leave all unchecked for full access)</span></label>

                    @php
                        $selectedScopes = old('scopes', []);
                        $scopeGroups = [
                            'Domains'           => ['domains.read', 'domains.write'],
                            'DNS Records'       => ['dns.read', 'dns.write'],
                            'Clients'           => ['clients.read', 'clients.write'],
                            'Hosting Services'  => ['hosting.read', 'hosting.write'],
                            'SSL Certificates'  => ['ssl.read', 'ssl.write'],
                            'Internet Services' => ['internet.read', 'internet.write'],
                            'Tickets'           => ['tickets.read', 'tickets.write'],
                            'Domain Pricing'    => ['pricing.read'],
                            'Users'             => ['users.read', 'users.write'],
                            'Audit Log'         => ['audit.read'],
                        ];
                        $allScopes = \App\Models\ApiKey::SCOPES;
                    @endphp

                    <div style="margin-top:6px;">
                        @foreach($scopeGroups as $groupLabel => $groupScopes)
                            <div class="ak-scope-group">
                                <div class="ak-scope-group-label">{{ $groupLabel }}</div>
                                <div class="ak-scope-pills">
                                    @foreach($groupScopes as $scope)
                                        @php
                                            $suffix = str_contains($scope, '.') ? substr($scope, strrpos($scope, '.') + 1) : $scope;
                                        @endphp
                                        <label class="ak-scope-pill" title="{{ $allScopes[$scope] ?? $scope }}">
                                            <input type="checkbox" name="scopes[]" value="{{ $scope }}"
                                                   {{ in_array($scope, $selectedScopes, true) ? 'checked' : '' }}>
                                            <span class="ak-scope-{{ $suffix }}">{{ $suffix }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @error('scopes') <p class="ak-error">{{ $message }}</p> @enderror
                    @error('scopes.*') <p class="ak-error">{{ $message }}</p> @enderror
                </div>

            </form>
        </div>

        <div class="ak-dialog-footer">
            <button type="button" class="ak-btn-secondary" id="modal-create-cancel">Cancel</button>
            <button type="submit" form="form-create-key" class="btn-accent" style="padding:8px 16px;">
                Generate key
            </button>
        </div>

    </div>
</div>

{{-- ══════════════════════════════════════════════════════════
     Edit key modal  (populated by JS)
════════════════════════════════════════════════════════════ --}}
<div id="modal-edit" class="ak-overlay" role="dialog" aria-modal="true" aria-labelledby="modal-edit-title">
    <div class="ak-dialog">

        <div class="ak-dialog-header">
            <h2 id="modal-edit-title" class="ak-dialog-title">Edit API key</h2>
            <button class="ak-dialog-close" id="modal-edit-close" aria-label="Close">&times;</button>
        </div>

        <div class="ak-dialog-body">
            <form method="POST" id="form-edit-key" action="">
                @csrf
                @method('PUT')

                <p id="edit-key-name"
                   style="font-size:13px;color:var(--text-muted);margin:0 0 14px;"></p>

                <div class="ak-field">
                    <label class="ak-label" for="edit_allowed_ips">
                        Allowed IPs <span>(optional)</span>
                    </label>
                    <input id="edit_allowed_ips" class="ak-input" name="allowed_ips" type="text"
                           placeholder="Comma-separated — e.g. 1.2.3.4, 10.0.0.0/8">
                    <p class="ak-hint">Leave blank to allow requests from any IP address.</p>
                </div>

                <div class="ak-field">
                    <label class="ak-label" for="edit_rate_limit">Rate limit per hour</label>
                    <input id="edit_rate_limit" class="ak-input" name="rate_limit_per_hour"
                           type="number" min="1">
                </div>

                <div class="ak-field" style="margin-bottom:0;">
                    <label class="ak-label">Scopes <span>(leave all unchecked for full access)</span></label>
                    <div style="margin-top:6px;" id="edit-scopes-container">
                        @foreach($scopeGroups as $groupLabel => $groupScopes)
                            <div class="ak-scope-group">
                                <div class="ak-scope-group-label">{{ $groupLabel }}</div>
                                <div class="ak-scope-pills">
                                    @foreach($groupScopes as $scope)
                                        @php
                                            $suffix = str_contains($scope, '.') ? substr($scope, strrpos($scope, '.') + 1) : $scope;
                                        @endphp
                                        <label class="ak-scope-pill" title="{{ $allScopes[$scope] ?? $scope }}">
                                            <input type="checkbox" name="scopes[]"
                                                   value="{{ $scope }}"
                                                   class="edit-scope-cb">
                                            <span class="ak-scope-{{ $suffix }}">{{ $suffix }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

            </form>
        </div>

        <div class="ak-dialog-footer">
            <button type="button" class="ak-btn-secondary" id="modal-edit-cancel">Cancel</button>
            <button type="submit" form="form-edit-key" class="btn-accent" style="padding:8px 16px;">
                Save changes
            </button>
        </div>

    </div>
</div>

{{-- ══════════════════════════════════════════════════════════
     Key reveal modal (single-display — shown once after create/regenerate)
════════════════════════════════════════════════════════════ --}}
@if(session('new_api_key'))
<div id="modal-reveal" class="ak-overlay is-open" role="dialog" aria-modal="true" aria-labelledby="modal-reveal-title">
    <div class="ak-dialog ak-dialog-sm">

        <div class="ak-dialog-header">
            <h2 id="modal-reveal-title" class="ak-dialog-title">API key ready</h2>
            <button class="ak-dialog-close" id="modal-reveal-close" aria-label="Close">&times;</button>
        </div>

        <div class="ak-dialog-body">
            <div class="ak-warn">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                     style="flex-shrink:0;margin-top:1px;">
                    <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <span>Copy this key now. For security, it <strong>will not be shown again</strong> after you close this dialog.</span>
            </div>

            <label class="ak-label" for="reveal-key-value">Your API key</label>
            <div class="ak-key-row">
                <input id="reveal-key-value" class="ak-input" type="text" readonly
                       value="{{ session('new_api_key') }}">
                <button id="btn-copy-key" type="button" class="btn-accent"
                        style="flex-shrink:0;padding:9px 14px;white-space:nowrap;">
                    Copy
                </button>
            </div>
        </div>

        <div class="ak-dialog-footer">
            <button id="modal-reveal-done" type="button" class="btn-accent" style="padding:8px 20px;">
                Done
            </button>
        </div>

    </div>
</div>
@endif

<script>
document.addEventListener('DOMContentLoaded', function () {

    // ── Helpers ──────────────────────────────────────────────────────────
    function openModal(el)  { if (el) el.classList.add('is-open'); }
    function closeModal(el) { if (el) el.classList.remove('is-open'); }

    function wireClose(modalEl, ...ids) {
        ids.forEach(function (id) {
            var btn = document.getElementById(id);
            if (btn) btn.addEventListener('click', function () { closeModal(modalEl); });
        });
        if (modalEl) {
            modalEl.addEventListener('click', function (e) {
                if (e.target === modalEl) closeModal(modalEl);
            });
        }
    }

    // ── Create modal ─────────────────────────────────────────────────────
    var createModal = document.getElementById('modal-create');
    var btnNewKey   = document.getElementById('btn-new-key');

    if (btnNewKey) btnNewKey.addEventListener('click', function () { openModal(createModal); });
    wireClose(createModal, 'modal-create-close', 'modal-create-cancel');

    @if($errors->any())
    openModal(createModal);
    @endif

    // ── Edit modal ───────────────────────────────────────────────────────
    var editModal      = document.getElementById('modal-edit');
    var editForm       = document.getElementById('form-edit-key');
    var editKeyName    = document.getElementById('edit-key-name');
    var editAllowedIps = document.getElementById('edit_allowed_ips');
    var editRateLimit  = document.getElementById('edit_rate_limit');
    var editScopeCbs   = document.querySelectorAll('.edit-scope-cb');

    wireClose(editModal, 'modal-edit-close', 'modal-edit-cancel');

    document.querySelectorAll('.ak-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var name       = btn.dataset.keyName || '';
            var allowedIps = btn.dataset.allowedIps || '';
            var rateLimit  = btn.dataset.rateLimit  || '1000';
            var scopes     = JSON.parse(btn.dataset.scopes || '[]');
            var url        = btn.dataset.updateUrl;

            if (editKeyName)    editKeyName.textContent  = 'Editing: ' + name;
            if (editAllowedIps) editAllowedIps.value     = allowedIps;
            if (editRateLimit)  editRateLimit.value       = rateLimit;
            if (editForm)       editForm.action           = url;

            editScopeCbs.forEach(function (cb) {
                cb.checked = scopes.indexOf(cb.value) !== -1;
            });

            openModal(editModal);
        });
    });

    // ── Reveal modal ─────────────────────────────────────────────────────
    var revealModal = document.getElementById('modal-reveal');
    var btnCopy     = document.getElementById('btn-copy-key');
    var keyInput    = document.getElementById('reveal-key-value');

    wireClose(revealModal, 'modal-reveal-close', 'modal-reveal-done');

    if (btnCopy && keyInput) {
        btnCopy.addEventListener('click', function () {
            var orig = btnCopy.textContent;
            navigator.clipboard.writeText(keyInput.value).then(function () {
                btnCopy.textContent = 'Copied!';
                setTimeout(function () { btnCopy.textContent = orig; }, 2000);
            }).catch(function () {
                keyInput.select();
                document.execCommand('copy');
                btnCopy.textContent = 'Copied!';
                setTimeout(function () { btnCopy.textContent = orig; }, 2000);
            });
        });

        keyInput.addEventListener('click', function () { this.select(); });
    }
});
</script>

@endsection
