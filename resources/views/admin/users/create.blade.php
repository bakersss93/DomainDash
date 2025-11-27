@extends('layouts.app')

@section('content')
    <h1 style="font-size:18px;font-weight:600;margin-bottom:16px;">New User</h1>

    <form method="POST"
          action="{{ route('admin.users.store') }}"
          style="max-width:640px;">
        @csrf

        <div style="margin-bottom:12px;">
            <label for="first_name" style="display:block;font-size:14px;margin-bottom:4px;">
                First Name
            </label>
            <input id="first_name" name="first_name" type="text" required
                   value="{{ old('first_name') }}">
            @error('first_name')
                <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>
            @enderror
        </div>

        <div style="margin-bottom:12px;">
            <label for="last_name" style="display:block;font-size:14px;margin-bottom:4px;">
                Surname
            </label>
            <input id="last_name" name="last_name" type="text" required
                   value="{{ old('last_name') }}">
            @error('last_name')
                <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>
            @enderror
        </div>

        <div style="margin-bottom:12px;">
            <label for="email" style="display:block;font-size:14px;margin-bottom:4px;">
                Email Address (username)
            </label>
            <input id="email" name="email" type="email" required
                   value="{{ old('email') }}">
            @error('email')
                <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>
            @enderror
        </div>

        {{-- Permission group as fancy pill dropdown --}}
        <div style="margin-bottom:12px;">
            <label for="role" style="display:block;font-size:14px;margin-bottom:4px;">
                Permission group
            </label>

            <div class="fancy-select-wrapper">
                <select id="role" name="role" class="fancy-select" required>
                    <option value="">Select role</option>
                    <option value="Administrator" {{ old('role') === 'Administrator' ? 'selected' : '' }}>Administrator</option>
                    <option value="Technician"   {{ old('role') === 'Technician'   ? 'selected' : '' }}>Technician</option>
                    <option value="Customer"     {{ old('role') === 'Customer'     ? 'selected' : '' }}>Customer</option>
                </select>
            </div>

            @error('role')
                <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>
            @enderror
        </div>

        {{-- Client Organisations searchable multi-select --}}
        <div style="margin-bottom:16px;">
            <span style="display:block;font-size:14px;margin-bottom:4px;">
                Client Organisation(s)
            </span>

            <div class="client-picker" id="clientPicker">
                <div class="client-picker-toggle" id="clientPickerToggle">
                    <span class="client-picker-label" id="clientPickerLabel">
                        {{ count(old('client_ids', [])) ? count(old('client_ids')) . ' selected' : 'Select client organisation(s)' }}
                    </span>
                    <span class="client-picker-arrow">▾</span>
                </div>

                <div class="client-picker-panel" id="clientPickerPanel">
                    <input type="text"
                           class="client-picker-search"
                           id="clientPickerSearch"
                           placeholder="Type to filter clients…">

                    <div class="client-picker-list" id="clientPickerList">
                        @forelse($clients as $client)
                            @php
                                $label = $client->business_name ?? $client->name ?? ('Client #'.$client->id);
                            @endphp
                            <label class="client-picker-item" data-label="{{ Str::lower($label) }}">
                                <input type="checkbox"
                                       name="client_ids[]"
                                       value="{{ $client->id }}"
                                       {{ in_array($client->id, old('client_ids', [])) ? 'checked' : '' }}>
                                {{ $label }}
                            </label>
                        @empty
                            <div style="font-size:13px;color:#6b7280;">No clients created yet.</div>
                        @endforelse
                    </div>
                </div>
            </div>

            @error('client_ids')
                <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>
            @enderror
        </div>

        {{-- Optional: set an initial password (otherwise auto-generate) --}}
        <div style="margin-bottom:12px;">
            <label for="password" style="display:block;font-size:14px;margin-bottom:4px;">
                Password (leave blank to auto-generate)
            </label>
            <input id="password" name="password" type="password">
            @error('password')
                <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>
            @enderror
        </div>

        <div style="margin-bottom:16px;">
            <label for="password_confirmation" style="display:block;font-size:14px;margin-bottom:4px;">
                Confirm Password
            </label>
            <input id="password_confirmation" name="password_confirmation" type="password">
        </div>

        <div style="display:flex;gap:8px;">
            <button type="submit" class="btn-accent">
                Save user
            </button>

            <a href="{{ route('admin.users') }}"
               style="padding:6px 14px;border-radius:4px;border:1px solid #e5e7eb;font-size:14px;text-decoration:none;">
                Cancel
            </a>
        </div>
    </form>

    {{-- Lightweight JS for the client picker --}}
    <script>
        (function () {
            const picker      = document.getElementById('clientPicker');
            const toggle      = document.getElementById('clientPickerToggle');
            const panel       = document.getElementById('clientPickerPanel');
            const searchInput = document.getElementById('clientPickerSearch');
            const labelEl     = document.getElementById('clientPickerLabel');
            const items       = Array.from(document.querySelectorAll('#clientPickerList .client-picker-item'));

            if (!picker || !toggle || !panel) return;

            function updateLabel() {
                const checked = items.filter(i => i.querySelector('input[type="checkbox"]').checked);
                if (!checked.length) {
                    labelEl.textContent = 'Select client organisation(s)';
                    return;
                }
                if (checked.length <= 2) {
                    labelEl.textContent = checked.map(i => i.textContent.trim()).join(', ');
                } else {
                    labelEl.textContent = checked.length + ' selected';
                }
            }

            function openPanel() {
                panel.classList.add('open');
            }

            function closePanel() {
                panel.classList.remove('open');
            }

            toggle.addEventListener('click', function (e) {
                e.stopPropagation();
                panel.classList.toggle('open');
            });

            document.addEventListener('click', function (e) {
                if (!picker.contains(e.target)) {
                    closePanel();
                }
            });

            items.forEach(function (item) {
                const checkbox = item.querySelector('input[type="checkbox"]');
                checkbox.addEventListener('change', updateLabel);
            });

            searchInput.addEventListener('keyup', function () {
                const q = this.value.toLowerCase();
                items.forEach(function (item) {
                    const label = item.getAttribute('data-label') || '';
                    item.style.display = label.includes(q) ? 'flex' : 'none';
                });
            });

            // initialise label based on old() selections
            updateLabel();
        })();
    </script>
@endsection
