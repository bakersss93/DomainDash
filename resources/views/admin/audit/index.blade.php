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
                            $eventPayload = [
                                'timestamp' => $log->created_at?->toDateTimeString(),
                                'event' => $log->action,
                                'user' => $log->user_email ?? optional($log->user)->email ?? '-',
                                'service' => $service,
                                'function' => $function,
                                'entity' => class_basename($log->auditable_type) . ' #' . ($log->auditable_id ?? '-'),
                                'description' => $log->description ?? '-',
                                'client_id' => $clientId,
                                'ip_address' => $log->ip_address,
                                'user_agent' => $log->user_agent,
                                'context' => $context,
                                'old_values' => $log->old_values ?? [],
                                'new_values' => $log->new_values ?? [],
                            ];
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
                                <button
                                    type="button"
                                    class="dd-account-password-btn dd-audit-view-btn"
                                    data-payload='@json($eventPayload)'
                                >
                                    View
                                </button>
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

<dialog id="audit-event-modal" class="dd-audit-modal" aria-labelledby="auditEventTitle">
    <div class="dd-audit-modal-panel">
        <div class="dd-account-modal-header dd-audit-modal-header">
            <div>
                <h2 id="auditEventTitle">Audit Event Details</h2>
                <p id="audit-event-headline" class="dd-audit-modal-headline">Event details and change history.</p>
            </div>
            <button type="button" class="dd-account-modal-close" id="audit-event-close" aria-label="Close audit event details">&times;</button>
        </div>
        <div class="dd-audit-tablist" role="tablist" aria-label="Audit details tabs">
            <button type="button" class="dd-audit-tab is-active" data-tab="details" role="tab" aria-selected="true">Details</button>
            <button type="button" class="dd-audit-tab" data-tab="changes" role="tab" aria-selected="false">Changes</button>
            <button type="button" class="dd-audit-tab" data-tab="difference" role="tab" aria-selected="false">Difference</button>
        </div>

        <section class="dd-audit-tab-panel is-active" data-tab-panel="details" role="tabpanel">
            <div class="dd-account-modal-grid">
                <div><strong>Timestamp:</strong> <span id="audit-event-time"></span></div>
                <div><strong>Event:</strong> <span id="audit-event-action"></span></div>
                <div><strong>User:</strong> <span id="audit-event-user"></span></div>
                <div><strong>Service / Function:</strong> <span id="audit-event-scope"></span></div>
                <div><strong>Entity:</strong> <span id="audit-event-entity"></span></div>
                <div><strong>Description:</strong> <span id="audit-event-description"></span></div>
                <div><strong>Client:</strong> <span id="audit-event-client"></span></div>
                <div><strong>IP Address:</strong> <span id="audit-event-ip"></span></div>
                <div style="grid-column: 1 / -1;"><strong>User Agent:</strong> <span id="audit-event-user-agent"></span></div>
            </div>
        </section>

        <section class="dd-audit-tab-panel" data-tab-panel="changes" role="tabpanel" hidden>
            <div><strong>Changed Fields:</strong></div>
            <div id="audit-event-diff-empty" style="display:none;margin-top:8px;color:var(--text-muted);">No field-level changes were captured for this event.</div>
            <div style="overflow:auto;">
                <table id="audit-event-diff-table" class="dd-table" style="width:100%;margin-top:8px;">
                    <thead>
                        <tr>
                            <th>Field</th>
                            <th>Previous Value</th>
                            <th>New Value</th>
                        </tr>
                    </thead>
                    <tbody id="audit-event-diff-body"></tbody>
                </table>
            </div>
            <div style="margin-top:12px;">
                <strong>Context Payload:</strong>
                <pre id="audit-event-context" class="dd-audit-code"></pre>
            </div>
        </section>

        <section class="dd-audit-tab-panel" data-tab-panel="difference" role="tabpanel" hidden>
            <div><strong>Old vs New (line view):</strong></div>
            <div id="audit-event-lines-empty" style="display:none;margin-top:8px;color:var(--text-muted);">No differences captured for this event.</div>
            <pre id="audit-event-diff-lines" class="dd-audit-code dd-audit-diff-lines"></pre>
        </section>
    </div>
</dialog>

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

.dd-audit-modal {
    width: min(1100px, 94vw);
    border: 0;
    padding: 0;
    background: transparent;
}

.dd-audit-modal::backdrop {
    background: rgba(2, 6, 23, 0.75);
}

.dd-audit-modal-panel {
    border: 1px solid var(--border-subtle);
    border-radius: 16px;
    background: var(--surface-elevated);
    color: var(--text);
    box-shadow: 0 24px 44px rgba(2, 6, 23, 0.6);
    padding: 20px;
    max-height: 84vh;
    overflow: auto;
    position: relative;
}

.dd-audit-modal-header {
    margin-bottom: 4px;
    padding-right: 52px;
}

.dd-audit-modal .dd-account-modal-close {
    border: 1px solid var(--border-subtle);
    background: var(--surface-muted);
    color: var(--text);
    width: 34px;
    height: 34px;
    border-radius: 999px;
    position: absolute;
    top: 14px;
    right: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    line-height: 1;
    cursor: pointer;
}

.dd-audit-modal-headline {
    margin: 6px 0 0;
    color: var(--text-muted);
}

.dd-audit-tablist {
    display: flex;
    gap: 8px;
    margin: 14px 0 16px;
    border-bottom: 1px solid var(--border-subtle);
}

.dd-audit-tab {
    border: 0;
    border-bottom: 3px solid transparent;
    background: transparent;
    color: var(--text-muted);
    padding: 10px 2px;
    font-weight: 600;
    cursor: pointer;
}

.dd-audit-tab.is-active {
    color: var(--text);
    border-bottom-color: var(--accent);
}

.dd-audit-tab-panel {
    animation: ddAuditFadeIn 140ms ease-out;
}

.dd-audit-code {
    margin-top: 8px;
    white-space: pre-wrap;
    background: var(--surface-muted);
    border: 1px solid var(--border-subtle);
    border-radius: 10px;
    padding: 12px;
    overflow: auto;
}

.dd-audit-diff-lines {
    max-height: 46vh;
}

.dd-audit-diff-line {
    display: block;
    padding: 3px 6px;
    border-radius: 6px;
}

.dd-audit-diff-line--added {
    background: color-mix(in srgb, #22c55e 24%, transparent);
}

.dd-audit-diff-line--removed {
    background: color-mix(in srgb, #ef4444 24%, transparent);
}

@keyframes ddAuditFadeIn {
    from {
        opacity: 0;
        transform: translateY(6px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>

<script>
(function () {
    const searchInput = document.getElementById('audit-live-search');
    const body = document.getElementById('audit-log-body');
    const noResults = document.getElementById('audit-live-empty');
    const modal = document.getElementById('audit-event-modal');
    const close = document.getElementById('audit-event-close');
    const tabs = Array.from(document.querySelectorAll('.dd-audit-tab'));
    const panels = Array.from(document.querySelectorAll('[data-tab-panel]'));

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

    function normaliseValue(value) {
        if (value === null || typeof value === 'undefined' || value === '') {
            return '-';
        }

        if (typeof value === 'object') {
            return JSON.stringify(value);
        }

        return String(value);
    }

    function isPlainObject(value) {
        return Object.prototype.toString.call(value) === '[object Object]';
    }

    function flattenDiffValues(value, path = '', output = {}) {
        if (Array.isArray(value)) {
            if (value.length === 0 && path) {
                output[path] = [];
                return output;
            }

            value.forEach((entry, index) => {
                const nextPath = path ? `${path}[${index}]` : `[${index}]`;
                flattenDiffValues(entry, nextPath, output);
            });
            return output;
        }

        if (isPlainObject(value)) {
            const entries = Object.entries(value).filter(([, entryValue]) => typeof entryValue !== 'function');
            if (entries.length === 0 && path) {
                output[path] = {};
                return output;
            }

            entries.forEach(([key, entryValue]) => {
                const nextPath = path ? `${path}.${key}` : key;
                flattenDiffValues(entryValue, nextPath, output);
            });
            return output;
        }

        if (path) {
            output[path] = value;
        }

        return output;
    }

    function getChangedFieldKeys(payload) {
        const oldValues = payload.old_values && typeof payload.old_values === 'object' ? payload.old_values : {};
        const newValues = payload.new_values && typeof payload.new_values === 'object' ? payload.new_values : {};
        const oldFlat = flattenDiffValues(oldValues);
        const newFlat = flattenDiffValues(newValues);
        const allKeys = Array.from(new Set(Object.keys(oldFlat).concat(Object.keys(newFlat)))).sort();
        const changedKeys = allKeys.filter((key) => normaliseValue(oldFlat[key]) !== normaliseValue(newFlat[key]));

        return {
            changedKeys,
            oldFlat,
            newFlat,
        };
    }

    function renderDiff(payload) {
        const diffBody = document.getElementById('audit-event-diff-body');
        const diffTable = document.getElementById('audit-event-diff-table');
        const diffEmpty = document.getElementById('audit-event-diff-empty');

        if (!diffBody || !diffTable || !diffEmpty) {
            return;
        }

        diffBody.innerHTML = '';
        const { changedKeys, oldFlat, newFlat } = getChangedFieldKeys(payload);

        if (changedKeys.length === 0) {
            diffTable.style.display = 'none';
            diffEmpty.style.display = 'block';
            return;
        }

        diffTable.style.display = '';
        diffEmpty.style.display = 'none';

        changedKeys.forEach((key) => {
            const row = document.createElement('tr');
            const oldValue = normaliseValue(oldFlat[key]);
            const newValue = normaliseValue(newFlat[key]);
            const fieldCell = document.createElement('td');
            const oldCell = document.createElement('td');
            const newCell = document.createElement('td');
            fieldCell.textContent = key;
            oldCell.textContent = oldValue;
            newCell.textContent = newValue;
            row.appendChild(fieldCell);
            row.appendChild(oldCell);
            row.appendChild(newCell);
            diffBody.appendChild(row);
        });
    }

    function renderDifferenceLines(payload) {
        const linesContainer = document.getElementById('audit-event-diff-lines');
        const emptyState = document.getElementById('audit-event-lines-empty');
        if (!linesContainer || !emptyState) {
            return;
        }

        linesContainer.innerHTML = '';
        const { changedKeys, oldFlat, newFlat } = getChangedFieldKeys(payload);

        if (changedKeys.length === 0) {
            linesContainer.hidden = true;
            emptyState.style.display = 'block';
            return;
        }

        linesContainer.hidden = false;
        emptyState.style.display = 'none';

        changedKeys.forEach((key) => {
            const oldLine = document.createElement('span');
            oldLine.className = 'dd-audit-diff-line dd-audit-diff-line--removed';
            oldLine.textContent = `- ${key}: ${normaliseValue(oldFlat[key])}`;
            linesContainer.appendChild(oldLine);

            const newLine = document.createElement('span');
            newLine.className = 'dd-audit-diff-line dd-audit-diff-line--added';
            newLine.textContent = `+ ${key}: ${normaliseValue(newFlat[key])}`;
            linesContainer.appendChild(newLine);
        });
    }

    function activateTab(tabName) {
        tabs.forEach((tab) => {
            const isActive = tab.dataset.tab === tabName;
            tab.classList.toggle('is-active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        panels.forEach((panel) => {
            const isActive = panel.dataset.tabPanel === tabName;
            panel.classList.toggle('is-active', isActive);
            panel.hidden = !isActive;
        });
    }

    function openEventModal(button) {
        if (!modal) {
            return;
        }

        const payload = button.dataset.payload ? JSON.parse(button.dataset.payload) : {};

        document.getElementById('audit-event-time').textContent = payload.timestamp || '-';
        document.getElementById('audit-event-action').textContent = payload.event || '-';
        document.getElementById('audit-event-user').textContent = payload.user || '-';
        document.getElementById('audit-event-scope').textContent = `${payload.service || '-'} / ${payload.function || '-'}`;
        document.getElementById('audit-event-entity').textContent = payload.entity || '-';
        document.getElementById('audit-event-description').textContent = payload.description || '-';
        document.getElementById('audit-event-client').textContent = payload.client_id || '-';
        document.getElementById('audit-event-ip').textContent = payload.ip_address || '-';
        document.getElementById('audit-event-user-agent').textContent = payload.user_agent || '-';
        document.getElementById('audit-event-context').textContent = JSON.stringify(payload.context || {}, null, 2);
        document.getElementById('audit-event-headline').textContent = payload.description || 'Event details and change history.';

        renderDiff(payload);
        renderDifferenceLines(payload);
        activateTab('details');

        if (typeof modal.showModal === 'function') {
            modal.showModal();
            return;
        }

        modal.setAttribute('open', 'open');
    }

    function closeEventModal() {
        if (!modal) {
            return;
        }

        if (typeof modal.close === 'function') {
            modal.close();
            return;
        }

        modal.removeAttribute('open');
    }

    document.querySelectorAll('.dd-audit-view-btn').forEach((button) => {
        button.addEventListener('click', () => openEventModal(button));
    });
    tabs.forEach((tab) => {
        tab.addEventListener('click', () => activateTab(tab.dataset.tab || 'details'));
    });

    close?.addEventListener('click', closeEventModal);
    modal?.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeEventModal();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal && modal.hasAttribute('open')) {
            closeEventModal();
        }
    });

    searchInput?.addEventListener('input', applyLiveSearch);
})();
</script>
@endsection
