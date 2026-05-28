@extends('layouts.app')

@section('content')
    <div style="max-width: 960px; margin: 0 auto;">

        {{-- Header --}}
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
            <h1 style="font-size:18px;font-weight:600;">API Keys</h1>
            <button id="btn-new-key" class="btn-accent" style="padding:8px 14px;">
                New API key
            </button>
        </div>

        {{-- Existing keys card --}}
        <div style="background:rgba(15,23,42,0.4);border-radius:8px;padding:20px 24px;">
            <h2 style="font-size:16px;font-weight:600;margin-bottom:12px;">Existing API keys</h2>

            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead>
                <tr>
                    <th style="text-align:left;padding:8px 6px;border-bottom:1px solid #1f2937;">Name</th>
                    <th style="text-align:left;padding:8px 6px;border-bottom:1px solid #1f2937;">Allowed IPs</th>
                    <th style="text-align:left;padding:8px 6px;border-bottom:1px solid #1f2937;">Rate limit</th>
                    <th style="text-align:left;padding:8px 6px;border-bottom:1px solid #1f2937;">Scopes</th>
                    <th style="text-align:left;padding:8px 6px;border-bottom:1px solid #1f2937;">Created</th>
                    <th style="text-align:right;padding:8px 6px;border-bottom:1px solid #1f2937;">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($keys as $key)
                    <tr>
                        <td style="padding:8px 6px;border-bottom:1px solid #111827;">
                            {{ $key->name }}
                        </td>
                        <td style="padding:8px 6px;border-bottom:1px solid #111827;">
                            {{ $key->allowed_ips ?: 'Any' }}
                        </td>
                        <td style="padding:8px 6px;border-bottom:1px solid #111827;">
                            {{ $key->rate_limit_per_hour ?? '—' }} / hour
                        </td>
                        <td style="padding:8px 6px;border-bottom:1px solid #111827;">
                            @php
                                $scopes = $key->scopes ?? [];
                                if (is_string($scopes)) {
                                    $decoded = json_decode($scopes, true);
                                    if (is_array($decoded)) $scopes = $decoded;
                                }
                            @endphp
                            {{ $scopes ? implode(', ', $scopes) : 'All' }}
                        </td>
                        <td style="padding:8px 6px;border-bottom:1px solid #111827;">
                            {{ $key->created_at->diffForHumans() }}
                        </td>
                        <td style="padding:8px 6px;border-bottom:1px solid #111827;text-align:right;">
                            @if(method_exists($key, 'isActive') ? $key->isActive() : true)
                                <form method="POST"
                                      action="{{ route('admin.apikeys.deactivate', $key) }}"
                                      onsubmit="return confirm('Deactivate this API key? It will stop working immediately.');"
                                      style="display:inline;">
                                    @csrf
                                    <button type="submit"
                                            style="padding:6px 10px;border-radius:4px;border:1px solid #e5e7eb;
                                                   font-size:13px;background:transparent;">
                                        Deactivate
                                    </button>
                                </form>
                            @else
                                <span style="font-size:12px;color:#9ca3af;">Deactivated</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6"
                            style="padding:12px 6px;text-align:center;color:#9ca3af;">
                            No API keys created yet.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── Create key modal ──────────────────────────────────────────── --}}
    <div id="modal-create" class="dd-modal" style="display:none;">
        <div class="dd-modal-backdrop" id="modal-create-backdrop"></div>
        <div class="dd-modal-dialog" style="width:520px;">

            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                <h2 style="font-size:16px;font-weight:600;">New API key</h2>
                <button id="modal-create-close"
                        type="button"
                        style="background:none;border:none;cursor:pointer;font-size:20px;line-height:1;color:#9ca3af;">
                    &times;
                </button>
            </div>

            <form method="POST" action="{{ route('admin.apikeys.store') }}">
                @csrf

                {{-- Name --}}
                <div style="margin-bottom:12px;">
                    <label for="name" style="display:block;font-size:14px;margin-bottom:4px;">Name</label>
                    <input id="name"
                           name="name"
                           type="text"
                           value="{{ old('name') }}"
                           placeholder="Key name (e.g. Monitoring integration)"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #374151;background:var(--bg);color:var(--text);
                                  font-size:14px;box-sizing:border-box;">
                    @error('name')
                        <div style="color:#f87171;font-size:12px;margin-top:2px;">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Allowed IPs --}}
                <div style="margin-bottom:12px;">
                    <label for="allowed_ips" style="display:block;font-size:14px;margin-bottom:4px;">
                        Allowed IPs <span style="color:#9ca3af;">(optional)</span>
                    </label>
                    <input id="allowed_ips"
                           name="allowed_ips"
                           type="text"
                           value="{{ old('allowed_ips') }}"
                           placeholder="Comma-separated, e.g. 1.2.3.4, 5.6.7.8"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #374151;background:var(--bg);color:var(--text);
                                  font-size:14px;box-sizing:border-box;">
                    @error('allowed_ips')
                        <div style="color:#f87171;font-size:12px;margin-top:2px;">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Rate limit --}}
                <div style="margin-bottom:12px;">
                    <label for="rate_limit_per_hour" style="display:block;font-size:14px;margin-bottom:4px;">
                        Rate limit per hour
                    </label>
                    <input id="rate_limit_per_hour"
                           name="rate_limit_per_hour"
                           type="number"
                           min="1"
                           value="{{ old('rate_limit_per_hour', 1000) }}"
                           style="width:100%;padding:8px 10px;border-radius:4px;
                                  border:1px solid #374151;background:var(--bg);color:var(--text);
                                  font-size:14px;box-sizing:border-box;">
                    @error('rate_limit_per_hour')
                        <div style="color:#f87171;font-size:12px;margin-top:2px;">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Scopes --}}
                <div style="margin-bottom:18px;">
                    <label style="display:block;font-size:14px;margin-bottom:4px;">
                        Scopes <span style="color:#9ca3af;">(optional)</span>
                    </label>
                    <p style="font-size:12px;color:#9ca3af;margin-bottom:8px;">
                        Choose what this key is allowed to access. Leave all unchecked for full access.
                    </p>

                    @php
                        $selectedScopes = old('scopes', []);
                        $scopeGroups = [
                            'Domains'              => ['domains.read', 'domains.write'],
                            'DNS Records'          => ['dns.read', 'dns.write'],
                            'Clients'              => ['clients.read', 'clients.write'],
                            'Hosting Services'     => ['hosting.read', 'hosting.write'],
                            'SSL Certificates'     => ['ssl.read', 'ssl.write'],
                            'Internet Services'    => ['internet.read', 'internet.write'],
                            'Tickets'              => ['tickets.read', 'tickets.write'],
                            'Domain Pricing'       => ['pricing.read'],
                            'Users'                => ['users.read', 'users.write'],
                            'Audit Log'            => ['audit.read'],
                        ];
                        $allScopes = \App\Models\ApiKey::SCOPES;
                    @endphp

                    <div style="display:grid;gap:10px;">
                        @foreach($scopeGroups as $groupLabel => $groupScopes)
                            <div>
                                <div style="font-size:12px;font-weight:600;color:#6b7280;
                                            text-transform:uppercase;letter-spacing:0.05em;
                                            margin-bottom:4px;">
                                    {{ $groupLabel }}
                                </div>
                                <div style="display:flex;flex-wrap:wrap;gap:6px;">
                                    @foreach($groupScopes as $scope)
                                        @php
                                            $suffix = str_contains($scope, '.') ? substr($scope, strrpos($scope, '.') + 1) : $scope;
                                            $description = $allScopes[$scope] ?? $scope;
                                        @endphp
                                        <label title="{{ $description }}"
                                               style="display:inline-flex;align-items:center;gap:6px;
                                                      padding:5px 10px;border-radius:9999px;cursor:pointer;
                                                      border:1px solid #374151;background:#0b1120;font-size:13px;">
                                            <input type="checkbox" name="scopes[]" value="{{ $scope }}"
                                                   {{ in_array($scope, $selectedScopes, true) ? 'checked' : '' }}>
                                            <span style="color:{{ $suffix === 'write' ? '#fbbf24' : '#93c5fd' }};">
                                                {{ $suffix }}
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @error('scopes')
                        <div style="color:#f87171;font-size:12px;margin-top:4px;">{{ $message }}</div>
                    @enderror
                    @error('scopes.*')
                        <div style="color:#f87171;font-size:12px;margin-top:4px;">{{ $message }}</div>
                    @enderror
                </div>

                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" id="modal-create-cancel"
                            style="padding:8px 14px;border-radius:4px;border:1px solid #374151;
                                   background:transparent;cursor:pointer;font-size:14px;">
                        Cancel
                    </button>
                    <button type="submit" class="btn-accent" style="padding:8px 14px;">
                        Generate key
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ── New key reveal modal ──────────────────────────────────────── --}}
    @if(session('new_api_key'))
    <div id="modal-reveal" class="dd-modal" style="display:flex;">
        <div class="dd-modal-backdrop"></div>
        <div class="dd-modal-dialog" style="width:480px;">

            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <h2 style="font-size:16px;font-weight:600;">API key created</h2>
                <button id="modal-reveal-close"
                        type="button"
                        style="background:none;border:none;cursor:pointer;font-size:20px;line-height:1;color:#9ca3af;">
                    &times;
                </button>
            </div>

            <p style="font-size:13px;color:#f87171;margin-bottom:12px;">
                Copy this key now — it will not be shown again.
            </p>

            <div style="display:flex;gap:8px;align-items:stretch;margin-bottom:18px;">
                <input id="reveal-key-value"
                       type="text"
                       readonly
                       value="{{ session('new_api_key') }}"
                       style="flex:1;padding:10px 12px;border-radius:4px;
                              border:1px solid #374151;background:#0b1120;color:#e5e7eb;
                              font-family:monospace;font-size:13px;word-break:break-all;">
                <button id="btn-copy-key"
                        type="button"
                        class="btn-accent"
                        style="padding:10px 14px;white-space:nowrap;flex-shrink:0;">
                    Copy
                </button>
            </div>

            <div style="text-align:right;">
                <button id="modal-reveal-done"
                        type="button"
                        class="btn-accent"
                        style="padding:8px 18px;">
                    Done
                </button>
            </div>
        </div>
    </div>
    @endif

    <style>
        .dd-modal {
            position: fixed;
            inset: 0;
            align-items: center;
            justify-content: center;
            z-index: 60;
        }
        .dd-modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(15,23,42,0.75);
        }
        .dd-modal-dialog {
            position: relative;
            background: var(--surface-elevated);
            border-radius: 12px;
            padding: 20px 22px 22px;
            border: 1px solid var(--border-subtle);
            max-width: 95%;
            box-shadow: 0 20px 40px rgba(0,0,0,.6);
            max-height: 90vh;
            overflow-y: auto;
        }
        html.dark .dd-modal-dialog {
            background: #020617;
            border-color: #1f2937;
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function () {

            // ── Create key modal ──────────────────────────────────────
            var createModal  = document.getElementById('modal-create');
            var btnNewKey    = document.getElementById('btn-new-key');
            var btnCreateClose  = document.getElementById('modal-create-close');
            var btnCreateCancel = document.getElementById('modal-create-cancel');
            var createBackdrop  = document.getElementById('modal-create-backdrop');

            function openCreateModal() {
                if (createModal) createModal.style.display = 'flex';
            }
            function closeCreateModal() {
                if (createModal) createModal.style.display = 'none';
            }

            if (btnNewKey)      btnNewKey.addEventListener('click', openCreateModal);
            if (btnCreateClose) btnCreateClose.addEventListener('click', closeCreateModal);
            if (btnCreateCancel) btnCreateCancel.addEventListener('click', closeCreateModal);
            if (createBackdrop)  createBackdrop.addEventListener('click', closeCreateModal);

            @if($errors->any())
            openCreateModal();
            @endif

            // ── Reveal modal ──────────────────────────────────────────
            var revealModal   = document.getElementById('modal-reveal');
            var btnRevealClose = document.getElementById('modal-reveal-close');
            var btnRevealDone  = document.getElementById('modal-reveal-done');
            var btnCopyKey     = document.getElementById('btn-copy-key');
            var keyValueInput  = document.getElementById('reveal-key-value');

            function closeRevealModal() {
                if (revealModal) revealModal.style.display = 'none';
            }

            if (btnRevealClose) btnRevealClose.addEventListener('click', closeRevealModal);
            if (btnRevealDone)  btnRevealDone.addEventListener('click', closeRevealModal);

            if (btnCopyKey && keyValueInput) {
                btnCopyKey.addEventListener('click', function () {
                    navigator.clipboard.writeText(keyValueInput.value).then(function () {
                        var orig = btnCopyKey.textContent;
                        btnCopyKey.textContent = 'Copied!';
                        setTimeout(function () { btnCopyKey.textContent = orig; }, 2000);
                    }).catch(function () {
                        keyValueInput.select();
                        document.execCommand('copy');
                    });
                });
            }
        });
    </script>
@endsection
