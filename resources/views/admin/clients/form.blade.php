@extends('layouts.app')

@section('content')
    <div style="max-width: 820px; margin: 0 auto;">

        {{-- Title --}}
        <h1 style="font-size:18px;font-weight:600;margin-bottom:16px;">
            {{ $client->exists ? 'Edit Client' : 'New Client' }}
        </h1>

        {{-- Debug info (remove after testing) --}}
        @if($client->exists && config('app.debug'))
            <div style="background:#1e293b;border:1px solid #334155;border-radius:6px;padding:12px;margin-bottom:16px;font-size:12px;font-family:monospace;">
                <strong>Debug Info:</strong><br>
                HaloPSA Ref: {{ $client->halopsa_reference ?? 'NULL' }}<br>
                ITGlue Org ID: {{ $client->itglue_org_id ?? 'NULL' }}<br>
                ITGlue Org Name: {{ $client->itglue_org_name ?? 'NULL' }}
            </div>
        @endif

        {{-- Form card --}}
        <div style="background:rgba(15,23,42,0.4);border-radius:8px;padding:20px 24px;margin-bottom:24px;">

            <form method="POST"
                  action="{{ $client->exists ? route('admin.clients.update', $client) : route('admin.clients.store') }}"
                  id="client-form">
                @csrf
                @if($client->exists)
                    @method('PUT')
                @endif

                {{-- Business name --}}
                <div style="margin-bottom:12px;">
                    <label for="business_name" style="display:block;font-size:14px;margin-bottom:4px;">
                        Business Name
                    </label>
                    <input id="business_name" name="business_name" type="text"
                           value="{{ old('business_name', $client->business_name) }}"
                           required
                           style="width:100%;padding:8px 10px;border-radius:4px;border:1px solid #e5e7eb;font-size:14px;">
                    @error('business_name')
                        <div style="color:#f87171;font-size:12px;margin-top:2px;">{{ $message }}</div>
                    @enderror
                </div>

                {{-- ABN --}}
                <div style="margin-bottom:12px;">
                    <label for="abn" style="display:block;font-size:14px;margin-bottom:4px;">ABN</label>
                    <input id="abn" name="abn" type="text"
                           value="{{ old('abn', $client->abn) }}"
                           style="width:100%;padding:8px 10px;border-radius:4px;border:1px solid #e5e7eb;font-size:14px;">
                    @error('abn')
                        <div style="color:#f87171;font-size:12px;margin-top:2px;">{{ $message }}</div>
                    @enderror
                </div>

                {{-- HaloPSA reference --}}
                <div style="margin-bottom:12px;">
                    <label for="halopsa_reference_display" style="display:block;font-size:14px;margin-bottom:4px;">
                        HaloPSA Reference
                    </label>

                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="text"
                               id="halopsa_reference_display"
                               value="{{ old('halopsa_reference', $client->halopsa_reference) }}"
                               placeholder="Selected HaloPSA client reference"
                               readonly
                               style="flex:1;padding:8px 10px;border-radius:4px;border:1px solid #e5e7eb;font-size:14px;background:#f9fafb;">

                        {{-- Button opens the HaloPSA picker modal --}}
                        <button type="button"
                                id="btn-halopsa-picker"
                                class="btn-accent"
                                style="white-space:nowrap;padding:8px 12px;">
                            {{ $client->halopsa_reference ? 'Change' : 'Select from HaloPSA' }}
                        </button>

                        @if($client->halopsa_reference)
                            <button type="button"
                                    id="btn-halopsa-clear"
                                    style="padding:8px 12px;border-radius:4px;border:1px solid #ef4444;color:#ef4444;background:transparent;white-space:nowrap;">
                                Clear
                            </button>
                        @endif
                    </div>

                    {{-- Hidden field to actually store the data --}}
                    <input type="hidden"
                           id="halopsa_reference"
                           name="halopsa_reference"
                           value="{{ old('halopsa_reference', $client->halopsa_reference) }}">

                    @error('halopsa_reference')
                        <div style="color:#f87171;font-size:12px;margin-top:2px;">{{ $message }}</div>
                    @enderror
                </div>

                {{-- ITGlue Organisation --}}
                <div style="margin-bottom:16px;">
                    <label style="display:block;font-size:14px;margin-bottom:4px;">
                        ITGlue Organisation
                    </label>

                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="text"
                               id="itglue_org_name_display"
                               value="{{ old('itglue_org_name', $client->itglue_org_name) }}"
                               placeholder="Selected ITGlue org name"
                               readonly
                               style="flex:1;padding:8px 10px;border-radius:4px;border:1px solid #e5e7eb;font-size:14px;background:#f9fafb;">

                        {{-- Button opens the ITGlue picker modal --}}
                        <button type="button"
                                id="btn-itglue-picker"
                                class="btn-accent"
                                style="white-space:nowrap;padding:8px 12px;">
                            Select from ITGlue
                        </button>
                        
                        @if($client->itglue_org_id)
                            <button type="button"
                                    id="btn-itglue-clear"
                                    style="padding:8px 12px;border-radius:4px;border:1px solid #ef4444;color:#ef4444;background:transparent;white-space:nowrap;">
                                Clear
                            </button>
                        @endif
                    </div>

                    {{-- Hidden fields to actually store the data --}}
                    <input type="hidden"
                           id="itglue_org_id"
                           name="itglue_org_id"
                           value="{{ old('itglue_org_id', $client->itglue_org_id) }}">
                    
                    <input type="hidden"
                           id="itglue_org_name"
                           name="itglue_org_name"
                           value="{{ old('itglue_org_name', $client->itglue_org_name) }}">

                    @error('itglue_org_id')
                        <div style="color:#f87171;font-size:12px;margin-top:2px;">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Status (Active checkbox pill-style) --}}
                <div style="margin-bottom:16px;">
                    <label style="display:block;font-size:14px;margin-bottom:4px;">Status</label>

                    @php
                        $isActive = old('active', $client->exists ? (bool)$client->active : true);
                    @endphp

                    <label style="display:inline-flex;align-items:center;gap:8px;
                                  padding:6px 12px;border-radius:9999px;
                                  border:1px solid #e5e7eb;background:#f9fafb;font-size:14px;cursor:pointer;">
                        <input type="checkbox"
                               name="active"
                               value="1"
                               {{ $isActive ? 'checked' : '' }}>
                        <span>Active</span>
                    </label>
                </div>

                {{-- Buttons --}}
                <div style="display:flex;gap:8px;align-items:center;justify-content:space-between;margin-top:8px;">
                    <div style="display:flex;gap:8px;">
                        <button type="submit" class="btn-accent" style="padding:8px 14px;">
                            Save
                        </button>

                        <a href="{{ route('admin.clients.index') }}"
                           style="padding:8px 14px;border-radius:4px;border:1px solid #e5e7eb;
                                  font-size:14px;text-decoration:none;">
                            Cancel
                        </a>
                    </div>

                    @if($client->exists)
                        <button type="button"
                                id="btn-delete-client"
                                style="padding:8px 14px;border-radius:4px;border:1px solid #ef4444;
                                       background:#ef4444;color:white;font-size:14px;cursor:pointer;">
                            Delete Client
                        </button>
                    @endif
                </div>
            </form>
        </div>

        {{-- Integration Actions (only show for existing clients) --}}
        @if($client->exists)
            <div style="background:rgba(15,23,42,0.4);border-radius:8px;padding:20px 24px;margin-bottom:24px;">
                <h2 style="font-size:16px;font-weight:600;margin-bottom:12px;">Integration Actions</h2>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    {{-- ITGlue Sync --}}
                    <div style="border:1px solid #1f2937;border-radius:6px;padding:14px;">
                        <h3 style="font-size:14px;font-weight:600;margin-bottom:6px;color:#60a5fa;">
                            📘 ITGlue Sync
                        </h3>
                        @if($client->itglue_org_id)
                            <p style="font-size:13px;color:#9ca3af;margin-bottom:10px;">
                                Sync domains to ITGlue with DNS records from Synergy
                            </p>
                            <button type="button"
                                    onclick="syncToItglue({{ $client->id }})"
                                    class="btn-accent"
                                    style="padding:6px 12px;font-size:13px;">
                                Sync Domains to ITGlue
                            </button>
                            <div id="itglue-sync-status" style="margin-top:8px;font-size:13px;"></div>
                        @else
                            <p style="font-size:13px;color:#9ca3af;margin-bottom:0;">
                                Link an ITGlue organization above to enable syncing
                            </p>
                        @endif
                    </div>

                    {{-- HaloPSA DNS Sync --}}
                    <div style="border:1px solid #1f2937;border-radius:6px;padding:14px;">
                        <h3 style="font-size:14px;font-weight:600;margin-bottom:6px;color:#34d399;">
                            🔧 HaloPSA DNS Sync
                        </h3>
                        @if($client->halopsa_reference)
                            @php
                                $domainsWithAssets = $client->domains()->whereNotNull('halo_asset_id')->count();
                            @endphp
                            <button type="button"
                                    onclick="linkHaloDomains({{ $client->id }})"
                                    class="btn-accent"
                                    style="padding:6px 12px;font-size:13px;margin-bottom:6px;">
                                🔄 Link HaloPSA Domains
                            </button>
                            <div id="halo-link-status" style="margin-top:2px;font-size:13px;"></div>
                            @if($domainsWithAssets > 0)
                                <p style="font-size:13px;color:#9ca3af;margin-bottom:10px;">
                                    Update HaloPSA asset notes with DNS records ({{ $domainsWithAssets }} domain{{ $domainsWithAssets !== 1 ? 's' : '' }})
                                </p>
                                <button type="button"
                                        onclick="syncDnsToHalo({{ $client->id }})"
                                        class="btn-accent"
                                        style="padding:6px 12px;font-size:13px;">
                                    Sync DNS to HaloPSA
                                </button>
                                <div id="halo-sync-status" style="margin-top:8px;font-size:13px;"></div>
                            @else
                                <p style="font-size:13px;color:#9ca3af;margin-bottom:0;">
                                    No domains with HaloPSA asset links found
                                </p>
                            @endif
                        @else
                            <p style="font-size:13px;color:#9ca3af;margin-bottom:0;">
                                Client must be imported from HaloPSA first
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        {{-- Assigned users list --}}
        @isset($assignedUsers)
            <div style="background:rgba(15,23,42,0.4);border-radius:8px;padding:16px 20px;">
                <h2 style="font-size:16px;font-weight:600;margin-bottom:8px;">Assigned Users</h2>

                @if($assignedUsers->isEmpty())
                    <div style="font-size:14px;color:#9ca3af;">No users assigned to this client yet.</div>
                @else
                    <ul style="list-style:none;padding-left:0;margin:0;font-size:14px;">
                        @foreach($assignedUsers as $user)
                            <li style="margin-bottom:4px;">
                                {{ $user->name }} &lt;{{ $user->email }}&gt;
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endisset
    </div>

    {{-- ITGlue organisation picker modal --}}
    <div id="itglue-modal-backdrop"
         style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.8);
                z-index:50;align-items:center;justify-content:center;">
        <div style="background:#020617;border-radius:12px;padding:20px 24px;
                    width:100%;max-width:720px;box-shadow:0 20px 40px rgba(0,0,0,0.45);">
            <h2 style="font-size:16px;font-weight:600;margin-bottom:12px;">
                Select ITGlue Organisation
            </h2>

            <p style="font-size:13px;color:#9ca3af;margin-bottom:12px;">
                Choose an organisation from ITGlue to link with this client.
            </p>

            {{-- Search input --}}
            <input type="text"
                   id="itglue-search"
                   placeholder="Search organisations..."
                   style="width:100%;padding:8px 12px;border-radius:6px;border:1px solid #1f2937;
                          font-size:14px;margin-bottom:12px;background:#0f172a;color:#e5e7eb;">

            <div id="itglue-loading"
                 style="font-size:14px;color:#9ca3af;margin:8px 0;">
                Loading organisations from ITGlue…
            </div>

            <div style="max-height:360px;overflow:auto;border-radius:6px;border:1px solid #1f2937;">
                <table style="width:100%;border-collapse:collapse;font-size:14px;">
                    <thead>
                    <tr style="background:#020617;">
                        <th data-sort="name" style="padding:8px 6px;border-bottom:1px solid #1f2937;text-align:left;cursor:pointer;user-select:none;">
                            Name <span class="sort-arrow">↕</span>
                        </th>
                        <th data-sort="ref" style="padding:8px 6px;border-bottom:1px solid #1f2937;text-align:left;cursor:pointer;user-select:none;">
                            Reference / ID <span class="sort-arrow">↕</span>
                        </th>
                        <th style="width:120px;padding:8px 6px;border-bottom:1px solid #1f2937;text-align:right;">&nbsp;</th>
                    </tr>
                    </thead>
                    <tbody id="itglue-tbody">
                    {{-- Populated by JS --}}
                    </tbody>
                </table>
            </div>

            <div id="itglue-empty"
                 style="display:none;font-size:14px;color:#9ca3af;margin-top:8px;">
                No organisations returned from ITGlue.
            </div>

            <div id="itglue-no-results"
                 style="display:none;font-size:14px;color:#9ca3af;margin-top:8px;">
                No organisations match your search.
            </div>

            <div id="itglue-error"
                 style="display:none;font-size:14px;color:#f97373;margin-top:8px;">
                Failed to load organisations from ITGlue. Please check the ITGlue API settings.
            </div>

            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
                <button type="button"
                        id="itglue-cancel"
                        style="padding:8px 14px;border-radius:4px;border:1px solid #e5e7eb;
                               font-size:14px;background:transparent;">
                    Cancel
                </button>
            </div>
        </div>
    </div>

    {{-- HaloPSA client picker modal --}}
    <div id="halopsa-modal-backdrop"
         style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.8);
                z-index:50;align-items:center;justify-content:center;">
        <div style="background:#020617;border-radius:12px;padding:20px 24px;
                    width:100%;max-width:720px;box-shadow:0 20px 40px rgba(0,0,0,0.45);">
            <h2 style="font-size:16px;font-weight:600;margin-bottom:12px;">
                Select HaloPSA Client
            </h2>

            <p style="font-size:13px;color:#9ca3af;margin-bottom:12px;">
                Choose a client from HaloPSA to link with this client.
            </p>

            {{-- Search input --}}
            <input type="text"
                   id="halopsa-search"
                   placeholder="Search clients..."
                   style="width:100%;padding:8px 12px;border-radius:6px;border:1px solid #1f2937;
                          font-size:14px;margin-bottom:12px;background:#0f172a;color:#e5e7eb;">

            <div id="halopsa-loading"
                 style="font-size:14px;color:#9ca3af;margin:8px 0;">
                Loading clients from HaloPSA…
            </div>

            <div style="max-height:360px;overflow:auto;border-radius:6px;border:1px solid #1f2937;">
                <table style="width:100%;border-collapse:collapse;font-size:14px;">
                    <thead>
                    <tr style="background:#020617;">
                        <th data-halosort="name" style="padding:8px 6px;border-bottom:1px solid #1f2937;text-align:left;cursor:pointer;user-select:none;">
                            Name <span class="halosort-arrow">↕</span>
                        </th>
                        <th data-halosort="reference" style="padding:8px 6px;border-bottom:1px solid #1f2937;text-align:left;cursor:pointer;user-select:none;">
                            Reference <span class="halosort-arrow">↕</span>
                        </th>
                        <th style="width:120px;padding:8px 6px;border-bottom:1px solid #1f2937;text-align:right;">&nbsp;</th>
                    </tr>
                    </thead>
                    <tbody id="halopsa-tbody">
                    {{-- Populated by JS --}}
                    </tbody>
                </table>
            </div>

            <div id="halopsa-empty"
                 style="display:none;font-size:14px;color:#9ca3af;margin-top:8px;">
                No clients returned from HaloPSA.
            </div>

            <div id="halopsa-no-results"
                 style="display:none;font-size:14px;color:#9ca3af;margin-top:8px;">
                No clients match your search.
            </div>

            <div id="halopsa-error"
                 style="display:none;font-size:14px;color:#f97373;margin-top:8px;">
                Failed to load clients from HaloPSA. Please check the HaloPSA API settings.
            </div>

            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
                <button type="button"
                        id="halopsa-cancel"
                        style="padding:8px 14px;border-radius:4px;border:1px solid #e5e7eb;
                               font-size:14px;background:transparent;">
                    Cancel
                </button>
            </div>
        </div>
    </div>

    {{-- Delete confirmation modal --}}
    <div id="delete-modal-backdrop"
         style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.9);
                z-index:50;align-items:center;justify-content:center;">
        <div style="background:#020617;border-radius:12px;padding:20px 24px;
                    width:100%;max-width:500px;box-shadow:0 20px 40px rgba(0,0,0,0.45);
                    border:2px solid #ef4444;">
            <h2 style="font-size:18px;font-weight:600;margin-bottom:12px;color:#ef4444;">
                ⚠️ Delete Client: {{ $client->business_name ?? 'Unnamed Client' }}
            </h2>

            <p style="font-size:14px;color:#e5e7eb;margin-bottom:16px;line-height:1.5;">
                This action <strong style="color:#ef4444;">cannot be undone</strong>. Deleting this client will permanently remove:
            </p>

            <ul style="font-size:13px;color:#9ca3af;margin-bottom:16px;padding-left:20px;">
                <li>Client record and all associated data</li>
                <li>Links to domains (domains will become unassigned)</li>
                <li>Links to users</li>
                <li>Integration references (HaloPSA, ITGlue)</li>
            </ul>

            <p style="font-size:14px;color:#e5e7eb;margin-bottom:12px;">
                To confirm deletion, please enter your email address:
            </p>

            <input type="email"
                   id="delete-email-confirm"
                   placeholder="your.email@example.com"
                   style="width:100%;padding:10px 12px;border-radius:6px;border:1px solid #1f2937;
                          font-size:14px;margin-bottom:16px;background:#0f172a;color:#e5e7eb;">

            <div id="delete-error" style="display:none;color:#ef4444;font-size:13px;margin-bottom:12px;"></div>

            <div style="display:flex;justify-content:flex-end;gap:8px;">
                <button type="button"
                        id="delete-cancel"
                        style="padding:8px 14px;border-radius:4px;border:1px solid #e5e7eb;
                               font-size:14px;background:transparent;">
                    Cancel
                </button>
                <button type="button"
                        id="delete-confirm"
                        style="padding:8px 14px;border-radius:4px;border:1px solid #ef4444;
                               background:#ef4444;color:white;font-size:14px;">
                    Delete Permanently
                </button>
            </div>
        </div>
    </div>

    {{-- Inline JS for ITGlue picker and form --}}
    <script>
        console.log('Client form loaded');
        
        (function () {
            const btnOpen   = document.getElementById('btn-itglue-picker');
            const btnClear  = document.getElementById('btn-itglue-clear');
            const backdrop  = document.getElementById('itglue-modal-backdrop');
            const tbody     = document.getElementById('itglue-tbody');
            const loadingEl = document.getElementById('itglue-loading');
            const emptyEl   = document.getElementById('itglue-empty');
            const errorEl   = document.getElementById('itglue-error');
            const btnCancel = document.getElementById('itglue-cancel');

            const displayInput = document.getElementById('itglue_org_name_display');
            const inputName = document.getElementById('itglue_org_name');
            const inputId   = document.getElementById('itglue_org_id');

            console.log('ITGlue form elements found:', {
                btnOpen: !!btnOpen,
                backdrop: !!backdrop,
                inputName: !!inputName,
                inputId: !!inputId,
                currentId: inputId ? inputId.value : 'N/A',
                currentName: inputName ? inputName.value : 'N/A'
            });

            if (!btnOpen || !backdrop) {
                console.error('ITGlue modal elements not found');
                return;
            }

            const itglueUrl = @json(route('admin.clients.itglue.search'));
            const searchInput = document.getElementById('itglue-search');
            const noResultsEl = document.getElementById('itglue-no-results');

            let hasLoaded = false;
            let allOrgs = [];
            let currentSort = { column: 'name', direction: 'asc' };

            function openModal() {
                console.log('Opening ITGlue modal');
                backdrop.style.display = 'flex';
                if (!hasLoaded) {
                    loadOrgs();
                }
            }

            function closeModal() {
                console.log('Closing ITGlue modal');
                backdrop.style.display = 'none';
            }

            function clearSelection() {
                console.log('Clearing ITGlue selection');
                if (inputName) inputName.value = '';
                if (inputId) inputId.value = '';
                if (displayInput) displayInput.value = '';
            }

            function normaliseOrg(org) {
                const attrs = org.attributes || {};

                const id =
                    org.id ??
                    org.Id ??
                    attrs.id ??
                    attrs.Id ??
                    attrs['organization_id'] ??
                    null;

                const name =
                    org.name ??
                    org.Name ??
                    attrs.name ??
                    attrs.Name ??
                    attrs['organisation_name'] ??
                    attrs['organization_name'] ??
                    id ??
                    'Unknown';

                const ref =
                    attrs.reference ??
                    attrs['reference'] ??
                    org.reference ??
                    org.Reference ??
                    '';

                return { id, name, ref, raw: org };
            }

            function loadOrgs() {
                hasLoaded = true;
                loadingEl.style.display = 'block';
                emptyEl.style.display   = 'none';
                noResultsEl.style.display = 'none';
                errorEl.style.display   = 'none';
                tbody.innerHTML         = '';

                console.log('Fetching ITGlue orgs from:', itglueUrl);

                fetch(itglueUrl, {
                    headers: { 'Accept': 'application/json' }
                })
                    .then(r => r.ok ? r.json() : Promise.reject())
                    .then(data => {
                        console.log('ITGlue response:', data);
                        loadingEl.style.display = 'none';

                        let list = [];
                        if (Array.isArray(data)) {
                            list = data;
                        } else if (Array.isArray(data.data)) {
                            list = data.data;
                        } else if (Array.isArray(data.results)) {
                            list = data.results;
                        }

                        if (!list.length) {
                            emptyEl.style.display = 'block';
                            return;
                        }

                        console.log('Processing', list.length, 'ITGlue orgs');

                        allOrgs = list.map(item => normaliseOrg(item));
                        renderOrgs();
                    })
                    .catch(() => {
                        console.error('Failed to load ITGlue orgs');
                        loadingEl.style.display = 'none';
                        errorEl.style.display   = 'block';
                    });
            }

            function renderOrgs() {
                const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';

                // Filter orgs
                let filteredOrgs = allOrgs;
                if (searchTerm) {
                    filteredOrgs = allOrgs.filter(org => {
                        const name = (org.name || '').toLowerCase();
                        const ref = (org.ref || '').toLowerCase();
                        const id = (org.id || '').toString().toLowerCase();
                        return name.includes(searchTerm) || ref.includes(searchTerm) || id.includes(searchTerm);
                    });
                }

                // Sort orgs
                filteredOrgs.sort((a, b) => {
                    let aVal, bVal;
                    if (currentSort.column === 'name') {
                        aVal = (a.name || '').toLowerCase();
                        bVal = (b.name || '').toLowerCase();
                    } else {
                        aVal = (a.ref || a.id || '').toString().toLowerCase();
                        bVal = (b.ref || b.id || '').toString().toLowerCase();
                    }

                    if (aVal < bVal) return currentSort.direction === 'asc' ? -1 : 1;
                    if (aVal > bVal) return currentSort.direction === 'asc' ? 1 : -1;
                    return 0;
                });

                // Clear tbody
                tbody.innerHTML = '';

                // Show/hide no results message
                if (filteredOrgs.length === 0) {
                    if (searchTerm) {
                        noResultsEl.style.display = 'block';
                    } else {
                        emptyEl.style.display = 'block';
                    }
                    return;
                } else {
                    noResultsEl.style.display = 'none';
                    emptyEl.style.display = 'none';
                }

                // Render rows
                filteredOrgs.forEach(org => {
                    const tr = document.createElement('tr');

                    tr.innerHTML = `
                        <td style="padding:6px;border-bottom:1px solid #111827;">${org.name}</td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">${org.ref || org.id || ''}</td>
                        <td style="padding:6px;border-bottom:1px solid #111827;text-align:right;">
                            <button type="button"
                                    class="btn-accent"
                                    style="padding:6px 10px;font-size:13px;">
                                Select
                            </button>
                        </td>
                    `;

                    const btnSelect = tr.querySelector('button');
                    btnSelect.addEventListener('click', function () {
                        console.log('Selected ITGlue org:', org);

                        if (displayInput) displayInput.value = org.name;
                        if (inputName) inputName.value = org.name;
                        if (inputId && org.id != null) inputId.value = org.id;

                        console.log('Form values set:', {
                            name: inputName ? inputName.value : 'N/A',
                            id: inputId ? inputId.value : 'N/A'
                        });

                        closeModal();
                    });

                    tbody.appendChild(tr);
                });
            }

            function sortBy(column) {
                if (currentSort.column === column) {
                    currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
                } else {
                    currentSort.column = column;
                    currentSort.direction = 'asc';
                }

                // Update sort arrows
                document.querySelectorAll('.sort-arrow').forEach(arrow => {
                    arrow.textContent = '↕';
                    arrow.style.opacity = '0.5';
                });

                const th = document.querySelector(`[data-sort="${column}"]`);
                if (th) {
                    const arrow = th.querySelector('.sort-arrow');
                    if (arrow) {
                        arrow.textContent = currentSort.direction === 'asc' ? '↑' : '↓';
                        arrow.style.opacity = '1';
                    }
                }

                renderOrgs();
            }

            btnOpen.addEventListener('click', openModal);
            if (btnClear) btnClear.addEventListener('click', clearSelection);
            btnCancel.addEventListener('click', closeModal);
            backdrop.addEventListener('click', function (e) {
                if (e.target === backdrop) {
                    closeModal();
                }
            });

            // Search input event listener
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    if (allOrgs.length > 0) {
                        renderOrgs();
                    }
                });
            }

            // Sort column event listeners
            document.querySelectorAll('[data-sort]').forEach(th => {
                th.addEventListener('click', function() {
                    const column = this.getAttribute('data-sort');
                    if (allOrgs.length > 0) {
                        sortBy(column);
                    }
                });
            });

            // Log form submission to debug
            const form = document.getElementById('client-form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    console.log('Form submitting with values:', {
                        business_name: document.getElementById('business_name')?.value,
                        halopsa_reference: document.getElementById('halopsa_reference')?.value,
                        itglue_org_id: document.getElementById('itglue_org_id')?.value,
                        itglue_org_name: document.getElementById('itglue_org_name')?.value,
                        active: document.querySelector('input[name="active"]')?.checked
                    });
                });
            }
        })();

        // ========================================================================
        // SYNC FUNCTIONS (for existing clients)
        // ========================================================================
        @if($client->exists)
        function syncToItglue(clientId) {
            if (!confirm('This will sync all client domains to ITGlue with DNS records from Synergy. Continue?')) {
                return;
            }

            const statusDiv = document.getElementById('itglue-sync-status');
            statusDiv.innerHTML = '<span style="color:#9ca3af;">⏳ Syncing...</span>';

            console.log('Starting ITGlue sync for client:', clientId);

            // Add timeout to fetch
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 120000); // 2 minute timeout

            fetch('/admin/clients/' + clientId + '/itglue/sync-domains', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json'
                },
                signal: controller.signal
            })
            .then(async r => {
                clearTimeout(timeoutId);
                console.log('ITGlue sync response status:', r.status);

                const contentType = r.headers.get('content-type');
                let data;

                if (contentType && contentType.includes('application/json')) {
                    data = await r.json();
                } else {
                    const text = await r.text();
                    console.error('Non-JSON response:', text.substring(0, 500));
                    throw new Error('Server returned non-JSON response');
                }

                if (!r.ok) {
                    console.error('ITGlue sync failed:', data);
                    throw new Error(data.error || data.message || 'HTTP ' + r.status);
                }

                return data;
            })
            .then(data => {
                console.log('ITGlue sync response:', data);

                if (data.success) {
                    let html = '<div style="color:#34d399;">✓ ' + data.message + '</div>';

                    if (data.results && data.results.length > 0) {
                        html += '<div style="margin-top:6px;font-size:12px;">';
                        data.results.forEach(result => {
                            const color = result.success ? '#34d399' : '#f87171';
                            html += '<div style="color:' + color + ';">• ' + result.domain + ': ' + result.message + '</div>';
                        });
                        html += '</div>';
                    }

                    statusDiv.innerHTML = html;
                } else {
                    statusDiv.innerHTML = '<div style="color:#f87171;">✗ ' + (data.error || data.message) + '</div>';
                }
            })
            .catch(err => {
                clearTimeout(timeoutId);
                console.error('Sync error:', err);

                let errorMsg = 'Error syncing to ITGlue';
                if (err.name === 'AbortError') {
                    errorMsg = 'Sync timed out after 2 minutes. Check server logs for details.';
                } else if (err.message) {
                    errorMsg = err.message;
                }

                statusDiv.innerHTML = '<div style="color:#f87171;">✗ ' + errorMsg + '</div>';
            })
            .finally(() => {
                clearTimeout(timeoutId);
            });
        }

        function linkHaloDomains(clientId) {
            const statusDiv = document.getElementById('halo-link-status');
            if (statusDiv) {
                statusDiv.innerHTML = '<span style="color:#9ca3af;">⏳ Checking HaloPSA assets...</span>';
            }

            fetch('/admin/clients/' + clientId + '/halo/link-domains', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json'
                }
            })
            .then(r => r.json())
            .then(data => {
                if (statusDiv) {
                    if (data.success) {
                        statusDiv.innerHTML = '<div style="color:#34d399;">✓ ' + data.message + '</div>';
                    } else {
                        statusDiv.innerHTML = '<div style="color:#f87171;">✗ ' + (data.error || data.message) + '</div>';
                    }
                }
            })
            .catch(err => {
                console.error('Linking error:', err);
                if (statusDiv) {
                    statusDiv.innerHTML = '<div style="color:#f87171;">✗ Error linking HaloPSA domains</div>';
                }
            });
        }

        function syncDnsToHalo(clientId) {
            if (!confirm('This will sync DNS records from Synergy to HaloPSA asset notes. Continue?')) {
                return;
            }

            const statusDiv = document.getElementById('halo-sync-status');
            statusDiv.innerHTML = '<span style="color:#9ca3af;">⏳ Syncing...</span>';

            fetch('/admin/clients/' + clientId + '/halo/sync-dns', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json'
                }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    let html = '<div style="color:#34d399;">✓ ' + data.message + '</div>';

                    if (data.results && data.results.length > 0) {
                        html += '<div style="margin-top:6px;font-size:12px;">';
                        data.results.forEach(result => {
                            const color = result.success ? '#34d399' : '#f87171';
                            html += '<div style="color:' + color + ';">• ' + result.domain + ': ' + result.message + '</div>';
                        });
                        html += '</div>';
                    }

                    statusDiv.innerHTML = html;
                } else {
                    statusDiv.innerHTML = '<div style="color:#f87171;">✗ ' + (data.error || data.message) + '</div>';
                }
            })
            .catch(err => {
                console.error('Sync error:', err);
                statusDiv.innerHTML = '<div style="color:#f87171;">✗ Error syncing DNS to HaloPSA</div>';
            });
        }
        @endif

        // ========================================================================
        // HALOPSA CLIENT PICKER
        // ========================================================================
        (function () {
            const haloBackdrop = document.getElementById('halopsa-modal-backdrop');
            const haloTbody = document.getElementById('halopsa-tbody');
            const haloLoadingEl = document.getElementById('halopsa-loading');
            const haloEmptyEl = document.getElementById('halopsa-empty');
            const haloNoResultsEl = document.getElementById('halopsa-no-results');
            const haloErrorEl = document.getElementById('halopsa-error');
            const haloBtnOpen = document.getElementById('btn-halopsa-picker');
            const haloBtnClear = document.getElementById('btn-halopsa-clear');
            const haloBtnCancel = document.getElementById('halopsa-cancel');
            const haloSearchInput = document.getElementById('halopsa-search');

            const haloDisplayInput = document.getElementById('halopsa_reference_display');
            const haloHiddenInput = document.getElementById('halopsa_reference');

            if (!haloBtnOpen || !haloBackdrop) {
                console.log('HaloPSA modal elements not found');
                return;
            }

            const haloUrl = @json(route('admin.clients.haloClients')) + '?show_all=1';

            let haloLoaded = false;
            let allHaloClients = [];
            let currentHaloSort = { column: 'name', direction: 'asc' };

            function openHaloModal() {
                console.log('Opening HaloPSA modal');
                haloBackdrop.style.display = 'flex';
                if (!haloLoaded) {
                    loadHaloClients();
                }
            }

            function closeHaloModal() {
                console.log('Closing HaloPSA modal');
                haloBackdrop.style.display = 'none';
            }

            function clearHaloSelection() {
                console.log('Clearing HaloPSA selection');
                if (haloHiddenInput) haloHiddenInput.value = '';
                if (haloDisplayInput) haloDisplayInput.value = '';
            }

            function loadHaloClients() {
                haloLoaded = true;
                haloLoadingEl.style.display = 'block';
                haloEmptyEl.style.display = 'none';
                haloNoResultsEl.style.display = 'none';
                haloErrorEl.style.display = 'none';
                haloTbody.innerHTML = '';

                console.log('Fetching HaloPSA clients from:', haloUrl);

                fetch(haloUrl, {
                    headers: { 'Accept': 'application/json' }
                })
                    .then(r => r.ok ? r.json() : Promise.reject())
                    .then(data => {
                        console.log('HaloPSA response:', data);
                        haloLoadingEl.style.display = 'none';

                        if (!Array.isArray(data) || data.length === 0) {
                            haloEmptyEl.style.display = 'block';
                            return;
                        }

                        console.log('Processing', data.length, 'HaloPSA clients');
                        allHaloClients = data;
                        renderHaloClients();
                    })
                    .catch(() => {
                        console.error('Failed to load HaloPSA clients');
                        haloLoadingEl.style.display = 'none';
                        haloErrorEl.style.display = 'block';
                    });
            }

            function renderHaloClients() {
                const searchTerm = haloSearchInput ? haloSearchInput.value.toLowerCase() : '';

                // Filter clients
                let filteredClients = allHaloClients;
                if (searchTerm) {
                    filteredClients = allHaloClients.filter(client => {
                        const name = (client.name || '').toLowerCase();
                        const ref = (client.reference || client.id || '').toString().toLowerCase();
                        return name.includes(searchTerm) || ref.includes(searchTerm);
                    });
                }

                // Sort clients
                filteredClients.sort((a, b) => {
                    let aVal, bVal;
                    if (currentHaloSort.column === 'name') {
                        aVal = (a.name || '').toLowerCase();
                        bVal = (b.name || '').toLowerCase();
                    } else {
                        aVal = (a.reference || a.id || '').toString().toLowerCase();
                        bVal = (b.reference || b.id || '').toString().toLowerCase();
                    }

                    if (aVal < bVal) return currentHaloSort.direction === 'asc' ? -1 : 1;
                    if (aVal > bVal) return currentHaloSort.direction === 'asc' ? 1 : -1;
                    return 0;
                });

                // Clear tbody
                haloTbody.innerHTML = '';

                // Show/hide no results message
                if (filteredClients.length === 0) {
                    if (searchTerm) {
                        haloNoResultsEl.style.display = 'block';
                    } else {
                        haloEmptyEl.style.display = 'block';
                    }
                    return;
                } else {
                    haloNoResultsEl.style.display = 'none';
                    haloEmptyEl.style.display = 'none';
                }

                // Render rows
                filteredClients.forEach(client => {
                    const tr = document.createElement('tr');

                    tr.innerHTML = `
                        <td style="padding:6px;border-bottom:1px solid #111827;">${client.name || 'Unknown'}</td>
                        <td style="padding:6px;border-bottom:1px solid #111827;">${client.reference || client.id || ''}</td>
                        <td style="padding:6px;border-bottom:1px solid #111827;text-align:right;">
                            <button type="button"
                                    class="btn-accent"
                                    style="padding:6px 10px;font-size:13px;">
                                Select
                            </button>
                        </td>
                    `;

                    const btnSelect = tr.querySelector('button');
                    btnSelect.addEventListener('click', function () {
                        console.log('Selected HaloPSA client:', client);

                        if (haloDisplayInput) haloDisplayInput.value = client.reference || client.id || '';
                        if (haloHiddenInput) haloHiddenInput.value = client.id || client.reference || '';

                        console.log('Form values set:', {
                            reference: haloHiddenInput ? haloHiddenInput.value : 'N/A'
                        });

                        closeHaloModal();
                    });

                    haloTbody.appendChild(tr);
                });
            }

            function sortHaloBy(column) {
                if (currentHaloSort.column === column) {
                    currentHaloSort.direction = currentHaloSort.direction === 'asc' ? 'desc' : 'asc';
                } else {
                    currentHaloSort.column = column;
                    currentHaloSort.direction = 'asc';
                }

                // Update sort arrows
                document.querySelectorAll('.halosort-arrow').forEach(arrow => {
                    arrow.textContent = '↕';
                    arrow.style.opacity = '0.5';
                });

                const th = document.querySelector(`[data-halosort="${column}"]`);
                if (th) {
                    const arrow = th.querySelector('.halosort-arrow');
                    if (arrow) {
                        arrow.textContent = currentHaloSort.direction === 'asc' ? '↑' : '↓';
                        arrow.style.opacity = '1';
                    }
                }

                renderHaloClients();
            }

            haloBtnOpen.addEventListener('click', openHaloModal);
            if (haloBtnClear) haloBtnClear.addEventListener('click', clearHaloSelection);
            haloBtnCancel.addEventListener('click', closeHaloModal);
            haloBackdrop.addEventListener('click', function (e) {
                if (e.target === haloBackdrop) {
                    closeHaloModal();
                }
            });

            // Search input event listener
            if (haloSearchInput) {
                haloSearchInput.addEventListener('input', function() {
                    if (allHaloClients.length > 0) {
                        renderHaloClients();
                    }
                });
            }

            // Sort column event listeners
            document.querySelectorAll('[data-halosort]').forEach(th => {
                th.addEventListener('click', function() {
                    const column = this.getAttribute('data-halosort');
                    if (allHaloClients.length > 0) {
                        sortHaloBy(column);
                    }
                });
            });
        })();

        // ========================================================================
        // DELETE CLIENT FUNCTIONALITY
        // ========================================================================
        @if($client->exists)
        (function () {
            const deleteBtn = document.getElementById('btn-delete-client');
            const deleteBackdrop = document.getElementById('delete-modal-backdrop');
            const deleteCancel = document.getElementById('delete-cancel');
            const deleteConfirm = document.getElementById('delete-confirm');
            const deleteEmailInput = document.getElementById('delete-email-confirm');
            const deleteError = document.getElementById('delete-error');

            if (!deleteBtn || !deleteBackdrop) {
                return;
            }

            function openDeleteModal() {
                deleteBackdrop.style.display = 'flex';
                deleteEmailInput.value = '';
                deleteError.style.display = 'none';
                deleteEmailInput.focus();
            }

            function closeDeleteModal() {
                deleteBackdrop.style.display = 'none';
                deleteEmailInput.value = '';
                deleteError.style.display = 'none';
            }

            async function confirmDelete() {
                const email = deleteEmailInput.value.trim();

                if (!email) {
                    deleteError.textContent = 'Please enter your email address to confirm deletion.';
                    deleteError.style.display = 'block';
                    return;
                }

                // Basic email validation
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(email)) {
                    deleteError.textContent = 'Please enter a valid email address.';
                    deleteError.style.display = 'block';
                    return;
                }

                // Disable button and show loading
                deleteConfirm.disabled = true;
                deleteConfirm.textContent = 'Deleting...';

                try {
                    const response = await fetch('{{ route("admin.clients.destroy", $client) }}', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json',
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            _method: 'DELETE',
                            confirmed_email: email
                        })
                    });

                    const data = await response.json().catch(() => ({}));

                    if (response.ok && data.success) {
                        // Success - redirect to clients index
                        window.location.href = '{{ route("admin.clients.index") }}';
                    } else {
                        deleteError.textContent = data.error || 'Failed to delete client. Please try again.';
                        deleteError.style.display = 'block';
                        deleteConfirm.disabled = false;
                        deleteConfirm.textContent = 'Delete Permanently';
                    }
                } catch (error) {
                    console.error('Delete error:', error);
                    deleteError.textContent = 'An error occurred. Please try again.';
                    deleteError.style.display = 'block';
                    deleteConfirm.disabled = false;
                    deleteConfirm.textContent = 'Delete Permanently';
                }
            }

            deleteBtn.addEventListener('click', openDeleteModal);
            deleteCancel.addEventListener('click', closeDeleteModal);
            deleteConfirm.addEventListener('click', confirmDelete);

            // Close on backdrop click
            deleteBackdrop.addEventListener('click', function(e) {
                if (e.target === deleteBackdrop) {
                    closeDeleteModal();
                }
            });

            // Allow Enter key to confirm
            deleteEmailInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    confirmDelete();
                }
            });
        })();
        @endif
    </script>
@endsection
