@extends('layouts.app')

@section('content')
    <div class="dd-account-wrap">
        <section class="dd-account-card">
            <header class="dd-account-header">
                <h1>Create User</h1>
                <p>Set up a new profile with role permissions and client access in one place.</p>
            </header>

            <div class="dd-account-grid">
                <form id="user-create-form" method="POST" action="{{ route('admin.users.store') }}">
                    @csrf

                    <h2 class="dd-account-section-title">Personal Information</h2>

                    <div class="dd-account-row">
                        <div class="dd-account-field">
                            <label for="first_name">First Name</label>
                            <input class="dd-account-input" id="first_name" name="first_name" type="text" required value="{{ old('first_name') }}">
                            @error('first_name')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
                        </div>

                        <div class="dd-account-field">
                            <label for="last_name">Last Name</label>
                            <input class="dd-account-input" id="last_name" name="last_name" type="text" required value="{{ old('last_name') }}">
                            @error('last_name')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="dd-account-field">
                        <label for="email">Email Address</label>
                        <input class="dd-account-input" id="email" name="email" type="email" required value="{{ old('email') }}">
                        @error('email')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
                    </div>

                    <h3 class="dd-account-subtitle">Access Controls</h3>

                    <div class="dd-account-field">
                        <label for="role">Permission Group</label>
                        <select class="dd-account-input" id="role" name="role" required>
                            <option value="">Select role</option>
                            <option value="Administrator" {{ old('role') === 'Administrator' ? 'selected' : '' }}>Administrator</option>
                            <option value="Technician" {{ old('role') === 'Technician' ? 'selected' : '' }}>Technician</option>
                            <option value="Customer" {{ old('role') === 'Customer' ? 'selected' : '' }}>Customer</option>
                        </select>
                        @error('role')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
                    </div>

                    <div class="dd-account-field">
                        <label>Client Organisation(s)</label>
                        <div class="client-picker" id="clientPicker">
                            <div class="client-picker-toggle" id="clientPickerToggle" style="width:100%;min-width:0;">
                                <span class="client-picker-label" id="clientPickerLabel">{{ count(old('client_ids', [])) ? count(old('client_ids')) . ' selected' : 'Select client organisation(s)' }}</span>
                                <span class="client-picker-arrow">▾</span>
                            </div>

                            <div class="client-picker-panel" id="clientPickerPanel" style="min-width:100%;max-width:none;">
                                <input type="text" class="client-picker-search" id="clientPickerSearch" placeholder="Type to filter clients...">
                                <div class="client-picker-list" id="clientPickerList">
                                    @forelse($clients as $client)
                                        @php $label = $client->business_name ?? $client->name ?? ('Client #'.$client->id); @endphp
                                        <label class="client-picker-item" data-label="{{ Str::lower($label) }}">
                                            <input type="checkbox" name="client_ids[]" value="{{ $client->id }}" {{ in_array($client->id, old('client_ids', [])) ? 'checked' : '' }}>
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
                        <button type="submit" class="btn-accent">Save user</button>
                        <button type="button" class="dd-account-secondary" style="min-width:130px;" onclick="window.location.href='{{ route('admin.users') }}'">Cancel</button>
                    </div>
                </form>

                <aside class="dd-account-aside">
                    <h3>Password Setup</h3>
                    <p>Leave password blank to auto-generate a secure credential for the user account.</p>
                    <div class="dd-account-field" style="margin-top:0.75rem;">
                        <label for="password">Password (optional)</label>
                        <input class="dd-account-input" id="password" name="password" type="password" form="user-create-form">
                    </div>
                    <div class="dd-account-field">
                        <label for="password_confirmation">Confirm Password</label>
                        <input class="dd-account-input" id="password_confirmation" name="password_confirmation" type="password" form="user-create-form">
                    </div>
                    <p style="margin-top:0.6rem;">If provided, ensure the password is at least 8 characters long and unique.</p>
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
@endsection
