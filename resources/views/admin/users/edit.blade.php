@extends('layouts.app')

@section('content')
    <div class="dd-account-wrap">
        <section class="dd-account-card">
            <header class="dd-account-header">
                <h1>Edit User</h1>
                <p>Manage profile details, access permissions, and client organisation links.</p>
            </header>

            <div class="dd-account-grid">
                <form method="POST" action="{{ route('admin.users.update', $user) }}">
                    @csrf
                    @method('PUT')

                    <h2 class="dd-account-section-title">Personal Information</h2>

                    <div class="dd-account-row">
                        <div class="dd-account-field">
                            <label for="first_name">First Name</label>
                            <input class="dd-account-input" id="first_name" name="first_name" type="text" required value="{{ old('first_name', $first) }}">
                            @error('first_name')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
                        </div>

                        <div class="dd-account-field">
                            <label for="last_name">Last Name</label>
                            <input class="dd-account-input" id="last_name" name="last_name" type="text" required value="{{ old('last_name', $last) }}">
                            @error('last_name')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="dd-account-field">
                        <label for="email">Email Address</label>
                        <input class="dd-account-input" id="email" name="email" type="email" required value="{{ old('email', $user->email) }}">
                        @error('email')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
                    </div>

                    <h3 class="dd-account-subtitle">Access Controls</h3>

                    <div class="dd-account-field">
                        <label for="role">Permission Group</label>
                        <select class="dd-account-input" id="role" name="role" required>
                            <option value="">Select role</option>
                            <option value="Administrator" {{ $currentRole === 'Administrator' ? 'selected' : '' }}>Administrator</option>
                            <option value="Technician" {{ $currentRole === 'Technician' ? 'selected' : '' }}>Technician</option>
                            <option value="Customer" {{ $currentRole === 'Customer' ? 'selected' : '' }}>Customer</option>
                        </select>
                        @error('role')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
                    </div>

                    <div class="dd-account-field">
                        <label>Client Organisation(s)</label>
                        <div class="client-picker" id="clientPicker">
                            <div class="client-picker-toggle" id="clientPickerToggle" style="width:100%;min-width:0;">
                                <span class="client-picker-label" id="clientPickerLabel">{{ $user->clients->count() ? $user->clients->pluck('business_name')->implode(', ') : 'Select client organisation(s)' }}</span>
                                <span class="client-picker-arrow">▾</span>
                            </div>

                            <div class="client-picker-panel" id="clientPickerPanel" style="min-width:100%;max-width:none;">
                                <input type="text" class="client-picker-search" id="clientPickerSearch" placeholder="Type to filter clients...">
                                <div class="client-picker-list" id="clientPickerList">
                                    @forelse($clients as $client)
                                        @php $label = $client->business_name ?? $client->name ?? ('Client #'.$client->id); @endphp
                                        <label class="client-picker-item" data-label="{{ Str::lower($label) }}">
                                            <input type="checkbox" name="client_ids[]" value="{{ $client->id }}" {{ in_array($client->id, old('client_ids', $currentClientIds)) ? 'checked' : '' }}>
                                            {{ $label }}
                                        </label>
                                    @empty
                                        <div style="font-size:13px;color:#6b7280;">No clients created yet.</div>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                        @error('client_ids')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
                    </div>

                    <div class="dd-account-actions">
                        <button type="submit" class="btn-accent">Save changes</button>
                        <a href="{{ route('admin.users') }}" class="dd-account-secondary">Cancel</a>
                    </div>
                </form>

                <aside class="dd-account-aside">
                    <h3>Password & Security</h3>
                    <p>Use the dedicated password screen to reset credentials and send secure reset links.</p>
                    <ul>
                        <li>Reset user password instantly.</li>
                        <li>Email a secure reset link.</li>
                        <li>Reset MFA enrolment if needed.</li>
                    </ul>
                    <a href="{{ route('admin.users.password.edit', $user) }}" class="dd-account-secondary" style="display:inline-block;margin-top:0.5rem;">Open Password Controls</a>
                </aside>
            </div>
        </section>
    </div>

    <script>
        (function () {
            const picker = document.getElementById('clientPicker');
            const toggle = document.getElementById('clientPickerToggle');
            const panel = document.getElementById('clientPickerPanel');
            const searchInput = document.getElementById('clientPickerSearch');
            const labelEl = document.getElementById('clientPickerLabel');
            const items = Array.from(document.querySelectorAll('#clientPickerList .client-picker-item'));

            if (!picker || !toggle || !panel) return;

            function updateLabel() {
                const checked = items.filter((item) => item.querySelector('input[type="checkbox"]').checked);
                if (!checked.length) {
                    labelEl.textContent = 'Select client organisation(s)';
                    return;
                }
                labelEl.textContent = checked.length <= 2
                    ? checked.map((item) => item.textContent.trim()).join(', ')
                    : checked.length + ' selected';
            }

            toggle.addEventListener('click', function (event) {
                event.stopPropagation();
                panel.classList.toggle('open');
            });

            document.addEventListener('click', function (event) {
                if (!picker.contains(event.target)) {
                    panel.classList.remove('open');
                }
            });

            items.forEach(function (item) {
                item.querySelector('input[type="checkbox"]').addEventListener('change', updateLabel);
            });

            searchInput.addEventListener('keyup', function () {
                const query = this.value.toLowerCase();
                items.forEach(function (item) {
                    const label = item.getAttribute('data-label') || '';
                    item.style.display = label.includes(query) ? 'flex' : 'none';
                });
            });

            updateLabel();
        })();
    </script>

    <style>
        .dd-account-wrap {
            max-width: 1200px;
            margin: 0 auto;
            padding-right: 8px;
        }

        .dd-account-card {
            border: 1px solid #263247 !important;
            border-radius: 20px !important;
            background: linear-gradient(180deg, #0f172a 0%, #0b1328 100%) !important;
            box-shadow: 0 30px 50px rgba(2, 6, 23, 0.55) !important;
            padding: 26px !important;
        }

        .dd-account-grid {
            display: grid !important;
            grid-template-columns: minmax(0, 1fr) 340px !important;
            gap: 20px !important;
            align-items: start;
        }

        .dd-account-row {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 14px !important;
        }

        .dd-account-input,
        .dd-account-field select {
            width: 100% !important;
            border: 1px solid #334155 !important;
            border-radius: 12px !important;
            background: #1e293b !important;
            color: #f8fafc !important;
            padding: 11px 12px !important;
        }

        .dd-account-header h1,
        .dd-account-section-title,
        .dd-account-subtitle,
        .dd-account-field label,
        .dd-account-aside h3 {
            color: #f8fafc !important;
        }

        .dd-account-header p,
        .dd-account-aside p,
        .dd-account-aside li {
            color: #a8b4c8 !important;
        }

        .dd-account-aside {
            border: 1px solid #334155 !important;
            background: #111827 !important;
            border-radius: 14px !important;
            padding: 16px !important;
        }

        @media (max-width: 1024px) {
            .dd-account-grid,
            .dd-account-row {
                grid-template-columns: 1fr !important;
            }
        }
    </style>
@endsection
