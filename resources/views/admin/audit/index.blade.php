@extends('layouts.app')

@section('content')
<div class="dd-page">
    <h1 class="dd-page-title">Audit & Logging</h1>

    <div class="dd-card" style="margin-bottom: 1rem;">
        <h2 style="margin-bottom: 0.75rem;">Retention & Storage</h2>
        <form method="POST" action="{{ route('admin.audit.retention') }}" class="dd-audit-retention-form">
            @csrf
            <div class="dd-audit-field">
                <label style="display:block;font-size:12px;margin-bottom:4px;">Retention days</label>
                <input type="number" name="retention_days" min="1" max="3650" value="{{ $retentionDays }}" required>
            </div>
            <label class="dd-audit-checkbox-label">
                <input type="checkbox" class="dd-audit-checkbox" name="prune_now" value="1">
                Prune now
            </label>
            <button type="submit" class="btn-accent">Save retention</button>
        </form>
        <div style="margin-top:0.8rem;color:#6b7280;">
            <div>Audit DB size (estimated): {{ number_format($dbBytes / 1024, 1) }} KB</div>
            <div>Laravel log file size: {{ number_format($logFileBytes / 1024, 1) }} KB</div>
        </div>
    </div>

    <div class="dd-card" style="margin-bottom: 1rem;">
        <h2 style="margin-bottom: 0.75rem;">Filters</h2>
        <form method="GET" class="dd-audit-filter-grid">
            <div class="dd-audit-field">
                <label style="display:block;font-size:12px;margin-bottom:4px;">Event</label>
                <select name="action">
                    <option value="">All events</option>
                    @foreach($actionOptions as $action)
                        <option value="{{ $action }}" {{ $filters['action'] === $action ? 'selected' : '' }}>{{ $action }}</option>
                    @endforeach
                </select>
            </div>
            <div class="dd-audit-field">
                <label style="display:block;font-size:12px;margin-bottom:4px;">User</label>
                <select name="user_id">
                    <option value="">All users</option>
                    @foreach($users as $user)
                        <option value="{{ $user->id }}" {{ $filters['user_id'] === (string) $user->id ? 'selected' : '' }}>
                            {{ $user->name }} ({{ $user->email }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="dd-audit-field">
                <label style="display:block;font-size:12px;margin-bottom:4px;">Client</label>
                <select name="client_id">
                    <option value="">All clients</option>
                    @foreach($clients as $client)
                        <option value="{{ $client->id }}" {{ $filters['client_id'] === (string) $client->id ? 'selected' : '' }}>{{ $client->business_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="dd-audit-field">
                <label style="display:block;font-size:12px;margin-bottom:4px;">Service</label>
                <select name="service">
                    <option value="">All services</option>
                    @foreach($serviceOptions as $serviceOption)
                        <option value="{{ $serviceOption }}" {{ $filters['service'] === $serviceOption ? 'selected' : '' }}>{{ $serviceOption }}</option>
                    @endforeach
                </select>
            </div>
            <div class="dd-audit-field">
                <label style="display:block;font-size:12px;margin-bottom:4px;">Function</label>
                <select name="function">
                    <option value="">All functions</option>
                    @foreach($functionOptions as $functionOption)
                        <option value="{{ $functionOption }}" {{ $filters['function'] === $functionOption ? 'selected' : '' }}>{{ $functionOption }}</option>
                    @endforeach
                </select>
            </div>
            <label class="dd-audit-checkbox-label dd-audit-field">
                <input type="checkbox" class="dd-audit-checkbox" name="failed_only" value="1" {{ $filters['failed_only'] ? 'checked' : '' }}>
                Failed only
            </label>
            <div class="dd-audit-actions">
                <button type="submit" class="btn-accent">Apply</button>
                <a href="{{ route('admin.audit.index') }}" class="btn-accent" style="text-decoration:none;background:#64748b;">Reset</a>
            </div>
        </form>
    </div>

    <div class="dd-card">
        <h2 style="margin-bottom: 0.75rem;">Event Timeline</h2>
        <div style="margin-bottom:0.75rem;max-width:360px;">
            <label for="audit-live-search" style="display:block;font-size:12px;margin-bottom:4px;">Live search in current results</label>
            <input id="audit-live-search" type="text" placeholder="Type to filter visible rows...">
        </div>
        <p id="audit-live-empty" style="display:none; margin:0 0 0.75rem; color:#6b7280;">No rows match the live search.</p>
        <div style="overflow:auto;">
            <table class="dd-table" style="width:100%; min-width:1200px;">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Event</th>
                        <th>User</th>
                        <th>Client</th>
                        <th>Service / Function</th>
                        <th>Description</th>
                        <th>Entity</th>
                        <th>Changes</th>
                    </tr>
                </thead>
                <tbody id="audit-log-body">
                    @forelse($logs as $log)
                        @php
                            $context = $log->context ?? [];
                            $clientId = $context['client_id'] ?? $log->new_values['client_id'] ?? $log->old_values['client_id'] ?? null;
                            $service = $context['service'] ?? '-';
                            $function = $context['function'] ?? '-';
                            $changePayload = json_encode([
                                'old' => $log->old_values,
                                'new' => $log->new_values,
                            ], JSON_PRETTY_PRINT);
                        @endphp
                        <tr data-audit-row>
                            <td>{{ $log->created_at?->toDateTimeString() }}</td>
                            <td>{{ $log->action }}</td>
                            <td>{{ $log->user_email ?? optional($log->user)->email ?? '-' }}</td>
                            <td>{{ $clientId ?? '-' }}</td>
                            <td>{{ $service }} / {{ $function }}</td>
                            <td>{{ $log->description ?? '-' }}</td>
                            <td>{{ class_basename($log->auditable_type) }} #{{ $log->auditable_id ?? '-' }}</td>
                            <td>
                                @if(!empty($log->old_values) || !empty($log->new_values))
                                    <button
                                        type="button"
                                        class="dd-account-password-btn dd-audit-view-btn"
                                        data-event="{{ $log->action }}"
                                        data-timestamp="{{ $log->created_at?->toDateTimeString() }}"
                                        data-user="{{ $log->user_email ?? optional($log->user)->email ?? '-' }}"
                                        data-service="{{ $service }}"
                                        data-function="{{ $function }}"
                                        data-description="{{ $log->description ?? '-' }}"
                                        data-entity="{{ class_basename($log->auditable_type) }} #{{ $log->auditable_id ?? '-' }}"
                                        data-changes="{{ $changePayload }}"
                                    >
                                        View
                                    </button>
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" style="text-align:center;color:#6b7280;">No audit events match the current filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top: 1rem;">
            {{ $logs->links() }}
        </div>
    </div>
</div>

<div id="audit-event-modal" class="dd-account-modal-backdrop" hidden>
    <div class="dd-account-modal" role="dialog" aria-modal="true" aria-labelledby="auditEventTitle">
        <div class="dd-account-modal-header">
            <h2 id="auditEventTitle">Audit Event Details</h2>
            <button type="button" class="dd-account-modal-close" id="audit-event-close" aria-label="Close audit event details">x</button>
        </div>
        <div class="dd-account-modal-grid">
            <div><strong>Timestamp:</strong> <span id="audit-event-time"></span></div>
            <div><strong>Event:</strong> <span id="audit-event-action"></span></div>
            <div><strong>User:</strong> <span id="audit-event-user"></span></div>
            <div><strong>Service / Function:</strong> <span id="audit-event-scope"></span></div>
            <div><strong>Entity:</strong> <span id="audit-event-entity"></span></div>
            <div><strong>Description:</strong> <span id="audit-event-description"></span></div>
            <div>
                <strong>Changes:</strong>
                <pre id="audit-event-changes" style="white-space:pre-wrap;margin-top:8px;"></pre>
            </div>
        </div>
    </div>
</div>

<style>
.dd-audit-retention-form {
    display: flex;
    gap: 0.75rem;
    align-items: flex-end;
    flex-wrap: wrap;
}

.dd-audit-filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 0.9rem;
    align-items: end;
}

.dd-audit-field {
    min-width: 0;
}

.dd-audit-field select,
.dd-audit-field input[type="text"],
.dd-audit-field input[type="number"] {
    width: 100%;
}

.dd-audit-actions {
    display: flex;
    gap: 0.5rem;
    align-items: center;
    flex-wrap: wrap;
}

.dd-audit-checkbox-label {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    font-size: 0.95rem;
    white-space: nowrap;
    padding-bottom: 0.2rem;
}

.dd-audit-checkbox {
    appearance: none;
    -webkit-appearance: none;
    width: 18px;
    height: 18px;
    border-radius: 999px;
    border: 1px solid var(--dd-border, #334155);
    background: var(--dd-surface-soft, #1f2937);
    position: relative;
    cursor: pointer;
    margin: 0;
}

.dd-audit-checkbox:checked {
    background: #22c55e;
    border-color: #22c55e;
}

.dd-audit-checkbox:checked::after {
    content: '';
    position: absolute;
    left: 6px;
    top: 3px;
    width: 4px;
    height: 8px;
    border: solid #fff;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}
</style>

<script>
(function () {
    const searchInput = document.getElementById('audit-live-search');
    const body = document.getElementById('audit-log-body');
    const noResults = document.getElementById('audit-live-empty');
    const modal = document.getElementById('audit-event-modal');
    const close = document.getElementById('audit-event-close');

    function applyLiveSearch() {
        if (!searchInput || !body) {
            return;
        }

        const term = searchInput.value.trim().toLowerCase();
        const rows = Array.from(body.querySelectorAll('tr[data-audit-row]'));
        let shown = 0;

        rows.forEach((row) => {
            const text = (row.textContent || '').toLowerCase();
            const visible = term === '' || text.includes(term);
            row.style.display = visible ? '' : 'none';
            if (visible) {
                shown += 1;
            }
        });

        if (noResults) {
            noResults.style.display = rows.length > 0 && shown === 0 ? 'block' : 'none';
        }
    }

    function openEventModal(button) {
        if (!modal) {
            return;
        }

        document.getElementById('audit-event-time').textContent = button.dataset.timestamp || '-';
        document.getElementById('audit-event-action').textContent = button.dataset.event || '-';
        document.getElementById('audit-event-user').textContent = button.dataset.user || '-';
        document.getElementById('audit-event-scope').textContent = `${button.dataset.service || '-'} / ${button.dataset.function || '-'}`;
        document.getElementById('audit-event-entity').textContent = button.dataset.entity || '-';
        document.getElementById('audit-event-description').textContent = button.dataset.description || '-';
        document.getElementById('audit-event-changes').textContent = button.dataset.changes || '{}';

        modal.hidden = false;
    }

    function closeEventModal() {
        if (modal) {
            modal.hidden = true;
        }
    }

    document.querySelectorAll('.dd-audit-view-btn').forEach((button) => {
        button.addEventListener('click', () => openEventModal(button));
    });

    close?.addEventListener('click', closeEventModal);
    modal?.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeEventModal();
        }
    });

    searchInput?.addEventListener('input', applyLiveSearch);
})();
</script>
@endsection
