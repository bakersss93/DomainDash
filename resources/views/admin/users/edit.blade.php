@extends('layouts.app')

@section('content')
    <div class="dd-user-shell">
        <h1 class="dd-page-title" style="font-size:1.6rem;">Edit User</h1>
        <p class="dd-user-subtitle">Update user identity, access role, and linked client organisations.</p>

        <form method="POST" action="{{ route('admin.users.update', $user) }}" class="dd-user-card">
            @csrf
            @method('PUT')

            <div class="dd-user-grid">
                <div class="dd-user-field">
                    <label for="first_name">First Name</label>
                    <input id="first_name" name="first_name" type="text" required value="{{ old('first_name', $first) }}">
                    @error('first_name')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
                </div>

                <div class="dd-user-field">
                    <label for="last_name">Surname</label>
                    <input id="last_name" name="last_name" type="text" required value="{{ old('last_name', $last) }}">
                    @error('last_name')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="dd-user-field">
                <label for="email">Email Address (username)</label>
                <input id="email" name="email" type="email" required value="{{ old('email', $user->email) }}">
                @error('email')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
            </div>

            <div class="dd-user-field">
                <label for="role">Permission Group</label>
                <div class="fancy-select-wrapper" style="width:100%;">
                    <select id="role" name="role" class="fancy-select" required style="width:100%;">
                        <option value="">Select role</option>
                        <option value="Administrator" {{ $currentRole === 'Administrator' ? 'selected' : '' }}>Administrator</option>
                        <option value="Technician" {{ $currentRole === 'Technician' ? 'selected' : '' }}>Technician</option>
                        <option value="Customer" {{ $currentRole === 'Customer' ? 'selected' : '' }}>Customer</option>
                    </select>
                </div>
                @error('role')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
            </div>

            <div class="dd-user-field">
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

            <div class="dd-user-actions">
                <button type="submit" class="btn-accent">Save changes</button>
                <a href="{{ route('admin.users') }}" class="dd-user-cancel">Cancel</a>
            </div>
        </form>
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
                const checked = items.filter(i => i.querySelector('input[type="checkbox"]').checked);
                if (!checked.length) {
                    labelEl.textContent = 'Select client organisation(s)';
                    return;
                }
                labelEl.textContent = checked.length <= 2
                    ? checked.map(i => i.textContent.trim()).join(', ')
                    : checked.length + ' selected';
            }

            toggle.addEventListener('click', function (e) {
                e.stopPropagation();
                panel.classList.toggle('open');
            });

            document.addEventListener('click', function (e) {
                if (!picker.contains(e.target)) {
                    panel.classList.remove('open');
                }
            });

            items.forEach(function (item) {
                item.querySelector('input[type="checkbox"]').addEventListener('change', updateLabel);
            });

            searchInput.addEventListener('keyup', function () {
                const q = this.value.toLowerCase();
                items.forEach(function (item) {
                    const label = item.getAttribute('data-label') || '';
                    item.style.display = label.includes(q) ? 'flex' : 'none';
                });
            });

            updateLabel();
        })();
    </script>
@endsection
