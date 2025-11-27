@extends('layouts.app')

@section('content')
    <h1 style="font-size:18px;font-weight:600;margin-bottom:16px;">Edit User</h1>

    <form method="POST"
          action="{{ route('admin.users.update', $user) }}"
          style="max-width:640px;">
        @csrf
        @method('PUT')

        <div style="margin-bottom:12px;">
            <label for="first_name" style="display:block;font-size:14px;margin-bottom:4px;">
                First Name
            </label>
            <input id="first_name" name="first_name" type="text" required
                   value="{{ old('first_name', $first) }}">
            @error('first_name')
                <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>
            @enderror
        </div>

        <div style="margin-bottom:12px;">
            <label for="last_name" style="display:block;font-size:14px;margin-bottom:4px;">
                Surname
            </label>
            <input id="last_name" name="last_name" type="text" required
                   value="{{ old('last_name', $last) }}">
            @error('last_name')
                <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>
            @enderror
        </div>

        <div style="margin-bottom:12px;">
            <label for="email" style="display:block;font-size:14px;margin-bottom:4px;">
                Email Address (username)
            </label>
            <input id="email" name="email" type="email" required
                   value="{{ old('email', $user->email) }}">
            @error('email')
                <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>
            @enderror
        </div>

        <div style="margin-bottom:12px;">
            <label for="role" style="display:block;font-size:14px;margin-bottom:4px;">
                Permission group
            </label>
            <div class="fancy-select-wrapper">
                <select id="role" name="role" class="fancy-select" required>
                    <option value="">Select role</option>
                    <option value="Administrator" {{ $currentRole === 'Administrator' ? 'selected' : '' }}>Administrator</option>
                    <option value="Technician"   {{ $currentRole === 'Technician'   ? 'selected' : '' }}>Technician</option>
                    <option value="Customer"     {{ $currentRole === 'Customer'     ? 'selected' : '' }}>Customer</option>
                </select>
            </div>
            @error('role')
                <div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>
            @enderror
        </div>

        {{-- Client Organisations (reuse same searchable picker as create) --}}
        <div style="margin-bottom:16px;">
            <span style="display:block;font-size:14px;margin-bottom:4px;">
                Client Organisation(s)
            </span>

            <div class="client-picker" id="clientPicker">
                <div class="client-picker-toggle" id="clientPickerToggle">
                    <span class="client-picker-label" id="clientPickerLabel">
                        {{ $user->clients->count() ? $user->clients->pluck('business_name')->implode(', ') : 'Select client organisation(s)' }}
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
                                       {{ in_array($client->id, old('client_ids', $currentClientIds)) ? 'checked' : '' }}>
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

        <div style="display:flex;gap:8px;">
            <button type="submit" class="btn-accent">
                Save changes
            </button>

            <a href="{{ route('admin.users') }}"
               style="padding:6px 14px;border-radius:4px;border:1px solid #e5e7eb;font-size:14px;text-decoration:none;">
                Cancel
            </a>
        </div>
    </form>

    {{-- reuse client-picker JS from create view --}}
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

            updateLabel();
        })();
    </script>
@endsection
