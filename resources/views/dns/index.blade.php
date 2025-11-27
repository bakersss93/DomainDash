@extends('layouts.app')

@section('content')
@php
    $dnsModes = [
        1 => 'Custom nameservers',
        2 => 'URL & Email Forwarding',
        3 => 'Parking',
        4 => 'DNS Hosting',
    ];

    $dnsModeInt = (int) ($dnsMode ?? 0);
    $currentNameservers = $nameservers ?? [];
@endphp

<div class="dd-dns-card">
    <h1 class="dd-dns-title">
        DNS records for {{ $domain->name }}
    </h1>

    <p class="dd-dns-subtitle">
        Manage DNS records and nameserver options. Changes may take time to propagate.
    </p>

    <p class="dd-dns-mode-line">
        Current DNS mode:
        <strong>{{ $dnsModes[$dnsModeInt] ?? 'Unknown' }}</strong>
    </p>

    {{-- Toolbar: Add record button (left) + right-aligned actions --}}
    <div class="dd-dns-toolbar">
        <div class="dd-dns-toolbar-left">
            @if($dnsModeInt !== 1)
                <button type="button"
                        class="btn-accent dd-pill-btn"
                        id="dd-dns-add-btn">
                    Add record
                </button>
            @endif
        </div>

        <div class="dd-dns-toolbar-right">
            <a href="{{ route('dns.index', $domain) }}" class="btn-accent dd-pill-btn">
                Refresh records
            </a>

            <button type="button" class="btn-accent dd-pill-btn" id="dd-dns-options-btn">
                DNS options
            </button>
        </div>
    </div>

    {{-- Main area: DNS records OR nameservers, depending on mode --}}
    @if($dnsModeInt === 1)
        {{-- Custom nameservers → show NS table only --}}
        <div class="dd-dns-table-wrapper">
            <table class="dd-dns-table">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Nameserver</th>
                </tr>
                </thead>
                <tbody>
                @forelse($currentNameservers as $i => $ns)
                    <tr>
                        <td style="width:80px;">NS{{ $i + 1 }}</td>
                        <td>
                            <input type="text"
                                   readonly
                                   class="dd-pill-input dd-pill-inline"
                                   value="{{ $ns }}">
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2" style="text-align:center;padding:12px 0;opacity:.7;">
                            No nameservers returned from Synergy.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <p class="dd-dns-mode-info">
            Zone records are not editable in <strong>Custom nameservers</strong> mode.
            Use <strong>DNS options</strong> to change nameservers or switch back to
            <strong>DNS Hosting</strong>.
        </p>
    @else
        {{-- All other modes → show DNS zone table --}}
        <div class="dd-dns-table-wrapper">
            <table class="dd-dns-table">
                <thead>
                <tr>
                    <th>Hostname</th>
                    <th>Type</th>
                    <th>Content</th>
                    <th>TTL</th>
                    <th>Priority</th>
                    <th style="width:160px;">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($records as $record)
                    @php $rowId = 'dns-row-' . $record->id; @endphp
                    <tr id="{{ $rowId }}">
                        <td>
                            <form id="dns-update-{{ $record->id }}"
                                  method="POST"
                                  action="{{ route('dns.update', [$domain->id, $record->id]) }}">
                                @csrf
                                @method('PUT')
                                <input type="text"
                                       name="host"
                                       value="{{ $record->host }}"
                                       class="dd-pill-input dd-pill-inline">
                        </td>
                        <td>
                                <select name="type" class="dd-pill-input dd-pill-inline dd-pill-select">
                                    @foreach(['A','AAAA','CNAME','MX','TXT','SRV','NS'] as $t)
                                        <option value="{{ $t }}" {{ $record->type === $t ? 'selected' : '' }}>
                                            {{ $t }}
                                        </option>
                                    @endforeach
                                </select>
                        </td>
                        <td>
                                <input type="text"
                                       name="content"
                                       value="{{ $record->content }}"
                                       class="dd-pill-input dd-pill-inline">
                        </td>
                        <td>
                                <input type="number"
                                       name="ttl"
                                       value="{{ $record->ttl }}"
                                       min="60"
                                       max="86400"
                                       class="dd-pill-input dd-pill-inline dd-pill-small">
                        </td>
                        <td>
                                <input type="number"
                                       name="prio"
                                       value="{{ $record->prio }}"
                                       min="0"
                                       max="65535"
                                       class="dd-pill-input dd-pill-inline dd-pill-small">
                            </form>
                        </td>
                        <td>
                            <div class="dd-dns-actions">
                                <button form="dns-update-{{ $record->id }}"
                                        type="submit"
                                        class="btn-accent dd-pill-btn">
                                    Save
                                </button>

                                <form method="POST"
                                      action="{{ route('dns.destroy', [$domain->id, $record->id]) }}"
                                      onsubmit="return confirm('Delete this DNS record?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-danger dd-pill-btn">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align:center;padding:12px 0;opacity:.7;">
                            No DNS records found for this domain.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- DNS options modal (popup) --}}
<div id="dd-dns-options-modal"
     class="dd-dns-modal dd-hidden"
     aria-hidden="true">
    <div class="dd-dns-modal-backdrop" data-dd-dns-options-close></div>

    <div class="dd-dns-modal-panel">
        <h2 class="dd-dns-modal-title">
            DNS options for {{ $domain->name }}
        </h2>

        <form method="POST" action="{{ route('dns.options', $domain) }}">
            @csrf

            <label class="dd-dns-options-label">
                DNS mode
                <select name="dns_mode"
                        class="dd-pill-input dd-pill-select"
                        id="dd-dns-mode-select">
                    @foreach($dnsModes as $value => $label)
                        <option value="{{ $value }}"
                            {{ $dnsModeInt === $value ? 'selected' : '' }}>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </label>

            {{-- Nameservers block – only used when Custom nameservers is selected --}}
            <div class="dd-dns-options-ns-block"
                 data-dd-dns-ns-block
                 @if($dnsModeInt !== 1) style="display:none;" @endif>
                <div class="dd-dns-options-label">
                    Nameservers (optional, up to 4)
                </div>

                @for($i = 0; $i < 4; $i++)
                    <input type="text"
                           name="nameservers[]"
                           class="dd-pill-input dd-dns-options-ns"
                           value="{{ $currentNameservers[$i] ?? '' }}"
                           placeholder="ns{{ $i+1 }}.example.com">
                @endfor
            </div>

            <div class="dd-dns-modal-actions">
                <button type="button"
                        class="btn-secondary dd-pill-btn"
                        data-dd-dns-options-close>
                    Cancel
                </button>

                <button type="submit" class="btn-accent dd-pill-btn">
                    Save DNS options
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Add DNS record modal --}}
@if($dnsModeInt !== 1)
<div id="dd-dns-add-modal"
     class="dd-dns-modal dd-hidden"
     aria-hidden="true">
    <div class="dd-dns-modal-backdrop" data-dd-dns-add-close></div>

    <div class="dd-dns-modal-panel">
        <h2 class="dd-dns-modal-title">
            Add DNS record for {{ $domain->name }}
        </h2>

        <form method="POST" action="{{ route('dns.store', $domain) }}">
            @csrf

            <label class="dd-dns-options-label">
                Host
                <input type="text"
                       name="host"
                       class="dd-pill-input"
                       placeholder="www or @">
            </label>

            <label class="dd-dns-options-label">
                Type
                <select name="type" class="dd-pill-input dd-pill-select">
                    @foreach(['A','AAAA','CNAME','MX','TXT','SRV','NS'] as $t)
                        <option value="{{ $t }}">{{ $t }}</option>
                    @endforeach
                </select>
            </label>

            <label class="dd-dns-options-label">
                Content
                <input type="text"
                       name="content"
                       class="dd-pill-input"
                       placeholder="Record target / value">
            </label>

            <div class="dd-dns-add-row">
                <label class="dd-dns-options-label dd-dns-add-col">
                    TTL
                    <input type="number"
                           name="ttl"
                           value="3600"
                           min="60"
                           max="86400"
                           class="dd-pill-input"
                           placeholder="TTL">
                </label>

                <label class="dd-dns-options-label dd-dns-add-col">
                    Priority
                    <input type="number"
                           name="prio"
                           value="0"
                           min="0"
                           max="65535"
                           class="dd-pill-input"
                           placeholder="Priority">
                </label>
            </div>

            <div class="dd-dns-modal-actions">
                <button type="button"
                        class="btn-secondary dd-pill-btn"
                        data-dd-dns-add-close>
                    Cancel
                </button>

                <button type="submit" class="btn-accent dd-pill-btn">
                    Save record
                </button>
            </div>
        </form>
    </div>
</div>
@endif

{{-- Inline CSS so it always loads, regardless of layout stacks --}}
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
        --dd-header-bg: #020617;
        --dd-header-text: #f9fafb;
        --dd-row-alt-bg: #f9f9fb;
        --dd-overlay-bg: rgba(15,23,42,0.65);
    }

    /* Dark mode – lighter grey pill background */
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
        --dd-overlay-bg: rgba(15,23,42,0.85);
    }

    .dd-dns-card {
        border-radius: var(--dd-card-radius);
        padding: var(--dd-card-padding);
        margin-top: 24px;
        border: 1px solid var(--dd-card-border);
        background: var(--dd-card-bg);
        color: var(--dd-text-color);
    }

    .dd-dns-title {
        font-size: 20px;
        font-weight: 600;
        margin-bottom: 4px;
    }

    .dd-dns-subtitle {
        font-size: 14px;
        opacity: 0.85;
        margin-bottom: 6px;
    }

    .dd-dns-mode-line {
        font-size: 13px;
        opacity: 0.8;
        margin-bottom: 14px;
    }

    .dd-dns-toolbar {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 14px;
        width: 100%;
    }

    .dd-dns-toolbar-left {
        flex: 1 1 auto;
    }

    .dd-dns-toolbar-right {
        display: flex;
        gap: 8px;
        align-items: center;
        margin-left: auto;
        flex: 0 0 auto;
    }

    .dd-dns-table-wrapper {
        overflow-x: auto;
    }

    .dd-dns-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }

    .dd-dns-table thead tr {
        background: var(--dd-header-bg);
        color: var(--dd-header-text);
    }

    .dd-dns-table th,
    .dd-dns-table td {
        padding: 8px 10px;
        text-align: left;
        border-bottom: 1px solid rgba(148,163,184,0.4);
    }

    .dd-dns-table tbody tr:nth-child(even) {
        background: var(--dd-row-alt-bg);
    }

    .dd-dns-actions {
        display: flex;
        gap: 6px;
        justify-content: flex-end;
    }

    .dd-dns-mode-info {
        margin-top: 16px;
        font-size: 14px;
        opacity: 0.9;
    }

    /* Pill style inputs & buttons */
    .dd-pill-input {
        border-radius: var(--dd-pill-radius) !important;
        border: 1px solid var(--dd-pill-border) !important;
        padding: var(--dd-pill-padding) !important;
        font-size: 14px;
        outline: none;
        width: 100%;
        background: var(--dd-pill-bg) !important;
        color: var(--dd-text-color) !important;
    }

    .dd-pill-input:focus {
        border-color: var(--accent, #4ade80) !important;
    }

    .dd-pill-small {
        max-width: 120px;
    }

    .dd-pill-select {
        min-width: 120px;
    }

    .dd-pill-inline {
        width: 100%;
    }

    .dd-pill-btn {
        border-radius: var(--dd-pill-radius) !important;
        padding: 8px 16px !important;
    }

    /* Shared modal styling (both options + add record) */
    .dd-dns-modal.dd-hidden {
        display: none;
    }

    .dd-dns-modal {
        position: fixed;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 999;
    }

    .dd-dns-modal-backdrop {
        position: absolute;
        inset: 0;
        background: var(--dd-overlay-bg);
    }

    .dd-dns-modal-panel {
        position: relative;
        z-index: 1000;
        max-width: 480px;
        width: 100%;
        border-radius: var(--dd-card-radius);
        padding: 20px;
        background: var(--dd-card-bg);
        border: 1px solid var(--dd-card-border);
    }

    .dd-dns-modal-title {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 12px;
    }

    .dd-dns-options-label {
        font-size: 13px;
        font-weight: 500;
        margin-top: 10px;
        margin-bottom: 4px;
    }

    .dd-dns-options-ns {
        margin-top: 4px;
    }

    .dd-dns-modal-actions {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        margin-top: 16px;
    }

    .dd-dns-add-row {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }

    .dd-dns-add-col {
        flex: 1 1 0;
        min-width: 0;
    }
</style>

{{-- Inline JS for modals + dynamic NS visibility --}}
<script>
document.addEventListener('DOMContentLoaded', function () {
    // ----- DNS options modal -----
    const optionsBtn   = document.getElementById('dd-dns-options-btn');
    const optionsModal = document.getElementById('dd-dns-options-modal');

    function closeOptionsModal() {
        if (!optionsModal) return;
        optionsModal.classList.add('dd-hidden');
        optionsModal.setAttribute('aria-hidden', 'true');
    }

    function openOptionsModal() {
        if (!optionsModal) return;
        optionsModal.classList.remove('dd-hidden');
        optionsModal.setAttribute('aria-hidden', 'false');
    }

    if (optionsBtn && optionsModal) {
        optionsBtn.addEventListener('click', openOptionsModal);

        optionsModal.querySelectorAll('[data-dd-dns-options-close]').forEach(function (el) {
            el.addEventListener('click', closeOptionsModal);
        });
    }

    // Nameserver block visibility in DNS options modal
    const dnsModeSelect = document.getElementById('dd-dns-mode-select');
    const nsBlock       = optionsModal
        ? optionsModal.querySelector('[data-dd-dns-ns-block]')
        : null;

    function syncNsVisibility() {
        if (!dnsModeSelect || !nsBlock) return;
        if (dnsModeSelect.value === '1') {
            nsBlock.style.display = '';
        } else {
            nsBlock.style.display = 'none';
        }
    }

    if (dnsModeSelect && nsBlock) {
        dnsModeSelect.addEventListener('change', syncNsVisibility);
        syncNsVisibility();
    }

    // ----- Add DNS record modal -----
    const addBtn   = document.getElementById('dd-dns-add-btn');
    const addModal = document.getElementById('dd-dns-add-modal');

    function closeAddModal() {
        if (!addModal) return;
        addModal.classList.add('dd-hidden');
        addModal.setAttribute('aria-hidden', 'true');
    }

    function openAddModal() {
        if (!addModal) return;
        addModal.classList.remove('dd-hidden');
        addModal.setAttribute('aria-hidden', 'false');
    }

    if (addBtn && addModal) {
        addBtn.addEventListener('click', openAddModal);

        addModal.querySelectorAll('[data-dd-dns-add-close]').forEach(function (el) {
            el.addEventListener('click', closeAddModal);
        });
    }

    // Global ESC key closes any open DNS modals
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeOptionsModal();
            closeAddModal();
        }
    });
});
</script>
@endsection
