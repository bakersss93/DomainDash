@extends('layouts.app')

@section('content')
    <div style="max-width: 820px; margin: 0 auto;">

        {{-- Title --}}
        <h1 style="font-size:18px;font-weight:600;margin-bottom:16px;">
            {{ $client->exists ? 'Edit Client' : 'New Client' }}
        </h1>

        {{-- Form card --}}
        <div style="background:rgba(15,23,42,0.4);border-radius:8px;padding:20px 24px;margin-bottom:24px;">

            <form method="POST"
                  action="{{ $client->exists ? route('admin.clients.update', $client) : route('admin.clients.store') }}">
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
                    <label for="halopsa_reference" style="display:block;font-size:14px;margin-bottom:4px;">
                        HaloPSA Reference
                    </label>
                    <input id="halopsa_reference" name="halopsa_reference" type="text"
                           value="{{ old('halopsa_reference', $client->halopsa_reference) }}"
                           style="width:100%;padding:8px 10px;border-radius:4px;border:1px solid #e5e7eb;font-size:14px;">
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
                        <input  type="text"
                                id="itglue_org_name"
                                name="itglue_org_name"
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
                    </div>

                    {{-- Hidden field to actually store the ID --}}
                        <input  type="hidden"
                                id="itglue_org_id"
                                name="itglue_org_id"
                                value="{{ old('itglue_org_id', $client->itglue_org_id) }}">

                    @error('itglue_org_id')
                        <div style="color:#f87171;font-size:12px;margin-top:2px;">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Status (Active checkbox pill-style) --}}
                <div style="margin-bottom:16px;">
                    <label style="display:block;font-size:14px;margin-bottom:4px;">Status</label>

                    @php
                        $isActive = old(
                            'active',
                            isset($client->active)
                                ? (bool)$client->active
                                : true
                        );
                    @endphp

                    <label style="display:inline-flex;align-items:center;gap:8px;
                                  padding:6px 12px;border-radius:9999px;
                                  border:1px solid #e5e7eb;background:#f9fafb;font-size:14px;">
                        <input type="checkbox"
                               name="active"
                               value="1"
                               {{ $isActive ? 'checked' : '' }}>
                        <span>Active</span>
                    </label>
                </div>

                {{-- Buttons --}}
                <div style="display:flex;gap:8px;align-items:center;margin-top:8px;">
                    <button type="submit" class="btn-accent" style="padding:8px 14px;">
                        Save
                    </button>

                    <a href="{{ route('admin.clients.index') }}"
                       style="padding:8px 14px;border-radius:4px;border:1px solid #e5e7eb;
                              font-size:14px;text-decoration:none;">
                        Cancel
                    </a>
                </div>
            </form>
        </div>

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

            <p style="font-size:13px;color:#9ca3af;margin-bottom:8px;">
                Choose an organisation from ITGlue to link with this client.
            </p>

            <div id="itglue-loading"
                 style="font-size:14px;color:#9ca3af;margin:8px 0;">
                Loading organisations from ITGlue…
            </div>

            <div style="max-height:360px;overflow:auto;border-radius:6px;border:1px solid #1f2937;">
                <table style="width:100%;border-collapse:collapse;font-size:14px;">
                    <thead>
                    <tr style="background:#020617;">
                        <th style="padding:8px 6px;border-bottom:1px solid #1f2937;text-align:left;">Name</th>
                        <th style="padding:8px 6px;border-bottom:1px solid #1f2937;text-align:left;">Reference / ID</th>
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

    {{-- Inline JS to wire the ITGlue button up --}}
    <script>
        (function () {
            const btnOpen   = document.getElementById('btn-itglue-picker');
            const backdrop  = document.getElementById('itglue-modal-backdrop');
            const tbody     = document.getElementById('itglue-tbody');
            const loadingEl = document.getElementById('itglue-loading');
            const emptyEl   = document.getElementById('itglue-empty');
            const errorEl   = document.getElementById('itglue-error');
            const btnCancel = document.getElementById('itglue-cancel');

            const inputName = document.getElementById('itglue_org_name');
            const inputId   = document.getElementById('itglue_org_id');

            if (!btnOpen || !backdrop) {
                return;
            }

            const itglueUrl = @json(route('admin.clients.itglue.search'));

            let hasLoaded = false;

            function openModal() {
                backdrop.style.display = 'flex';
                if (!hasLoaded) {
                    loadOrgs();
                }
            }

            function closeModal() {
                backdrop.style.display = 'none';
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
                errorEl.style.display   = 'none';
                tbody.innerHTML         = '';

                fetch(itglueUrl, {
                    headers: { 'Accept': 'application/json' }
                })
                    .then(r => r.ok ? r.json() : Promise.reject())
                    .then(data => {
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

                        list.forEach(item => {
                            const org = normaliseOrg(item);
                            const tr  = document.createElement('tr');

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
                                if (inputName) {
                                    inputName.value = org.name;
                                }
                                if (inputId && org.id != null) {
                                    inputId.value = org.id;
                                }
                                closeModal();
                            });

                            tbody.appendChild(tr);
                        });
                    })
                    .catch(() => {
                        loadingEl.style.display = 'none';
                        errorEl.style.display   = 'block';
                    });
            }

            btnOpen.addEventListener('click', openModal);
            btnCancel.addEventListener('click', closeModal);
            backdrop.addEventListener('click', function (e) {
                if (e.target === backdrop) {
                    closeModal();
                }
            });
        })();
    </script>
@endsection
