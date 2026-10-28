@extends('layouts.app')

@section('content')
    <div class="dd-account-wrap">
        <section class="dd-account-card">
            <header class="dd-account-header">
                <h1>Edit User</h1>
                <p>Manage profile details, access permissions, and client organisation links.</p>
            </header>

            <div class="dd-account-grid">
                <form method="POST" action="{{ route('admin.users.update', $user) }}" class="dd-account-main-form">
                    @csrf
                    @method('PUT')

                    <h2 class="dd-account-section-title">Personal Information</h2>

                    <div class="dd-account-row">
                        <div class="dd-account-field">
                            <label for="first_name">First Name</label>
                            <input class="dd-account-input" id="first_name" name="first_name" type="text" required value="{{ old('first_name', $first) }}">
                            @error('first_name')<div class="ui-error" role="alert" style="margin-top:0.25rem;">{{ $message }}</div>@enderror
                        </div>

                        <div class="dd-account-field">
                            <label for="last_name">Last Name</label>
                            <input class="dd-account-input" id="last_name" name="last_name" type="text" required value="{{ old('last_name', $last) }}">
                            @error('last_name')<div class="ui-error" role="alert" style="margin-top:0.25rem;">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="dd-account-field">
                        <label for="email">Email Address</label>
                        <input class="dd-account-input" id="email" name="email" type="email" required value="{{ old('email', $user->email) }}">
                        @error('email')<div class="ui-error" role="alert" style="margin-top:0.25rem;">{{ $message }}</div>@enderror
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
                        @error('role')<div class="ui-error" role="alert" style="margin-top:0.25rem;">{{ $message }}</div>@enderror
                    </div>


                    <div class="dd-account-field dd-account-toggle-field">
                        <input type="hidden" name="is_active" value="0">
                        <label class="dd-account-checkbox-row">
                            <input class="dd-account-checkbox" type="checkbox" name="is_active" value="1" {{ old('is_active', (int) $user->is_active) ? 'checked' : '' }}>
                            <span>Enable user account</span>
                        </label>
                        <small class="dd-account-help-text">If disabled, this user cannot log in and will be told to contact the system administrator.</small>
                        @error('is_active')<div class="ui-error" role="alert" style="margin-top:0.25rem;">{{ $message }}</div>@enderror
                    </div>

                    <div class="dd-account-field">
                        <label for="mfa_preference">MFA Policy</label>
                        <select class="dd-account-input" id="mfa_preference" name="mfa_preference" required>
                            <option value="enabled" {{ old('mfa_preference', $user->mfa_preference ?? 'enabled') === 'enabled' ? 'selected' : '' }}>Enabled (user can configure)</option>
                            <option value="enforced" {{ old('mfa_preference', $user->mfa_preference) === 'enforced' ? 'selected' : '' }}>Enforced (required)</option>
                            <option value="disabled" {{ old('mfa_preference', $user->mfa_preference) === 'disabled' ? 'selected' : '' }}>Disabled</option>
                        </select>
                        @error('mfa_preference')<div class="ui-error" role="alert" style="margin-top:0.25rem;">{{ $message }}</div>@enderror
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
                                            <input class="client-picker-checkbox" type="checkbox" name="client_ids[]" value="{{ $client->id }}" {{ in_array($client->id, old('client_ids', $currentClientIds)) ? 'checked' : '' }}>
                                            {{ $label }}
                                        </label>
                                    @empty
                                        <div style="font-size:13px;color:var(--dd-text-soft);">No clients created yet.</div>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                        @error('client_ids')<div class="ui-error" role="alert" style="margin-top:0.25rem;">{{ $message }}</div>@enderror
                    </div>

                    <div class="dd-account-actions" style="justify-content:flex-start;">
                        <button type="submit" class="btn-accent">Save changes</button>
                        <button type="button" class="dd-account-secondary" onclick="window.location.href='{{ route('admin.users') }}'">Cancel</button>
                    </div>
                </form>

                <aside class="dd-account-aside">
                    <h3>Password & Security</h3>
                    <p>Manage this user's sign-in access.</p>
                    <ul>
                        <li>Set a temporary password.</li>
                        <li>Send a reset link.</li>
                        <li>Reset MFA if needed.</li>
                    </ul>
                    <button type="button" class="dd-account-password-btn" id="openPasswordControls">Reset Password</button>

                    <hr style="margin:14px 0;border-color:var(--border-subtle);">
                    <h3 style="margin-top:0;">Danger Zone</h3>
                    <p style="font-size:13px;color:var(--text-muted);">Delete this user account permanently.</p>
                    <form method="POST" action="{{ route('admin.users.destroy', $user) }}" onsubmit="return confirm('Delete this user account permanently?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="dd-account-secondary" style="border-color:var(--danger-text);color:var(--danger-text);">Delete User</button>
                    </form>
                </aside>
            </div>
        </section>
    </div>

    <div class="dd-account-modal-backdrop" id="passwordModalBackdrop" hidden>
        <div class="dd-account-modal" role="dialog" aria-modal="true" aria-labelledby="passwordModalTitle">
            <div class="dd-account-modal-header">
                <h2 id="passwordModalTitle">Password Controls</h2>
                <button type="button" class="dd-account-modal-close" id="closePasswordControls" aria-label="Close password controls">×</button>
            </div>
            <p class="dd-account-modal-intro">Reset a password now or send a reset email.</p>

            <div class="dd-account-modal-grid">
                <form method="POST" action="{{ route('admin.users.password.update', $user) }}" class="dd-account-modal-form">
                    @csrf
                    @method('PUT')

                    <h3>Set Password</h3>
                    <div class="dd-account-field">
                        <label for="password">New Password</label>
                        <input class="dd-account-input" id="password" name="password" type="password" required>
                    </div>
                    <div class="dd-account-field">
                        <label for="password_confirmation">Confirm Password</label>
                        <input class="dd-account-input" id="password_confirmation" name="password_confirmation" type="password" required>
                    </div>
                    @error('password')<div class="ui-error" role="alert" style="margin-top:0.25rem;">{{ $message }}</div>@enderror
                    <button type="submit" class="btn-accent">Save password</button>
                </form>

                <form method="POST" action="{{ route('admin.users.password.link', $user) }}" class="dd-account-modal-form">
                    @csrf

                    <h3>Send Reset Link</h3>
                    <p>Email the user a secure link to set their own password.</p>
                    <button type="submit" class="dd-account-password-btn">Send reset email</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const picker = document.getElementById('clientPicker');
            const toggle = document.getElementById('clientPickerToggle');
            const panel = document.getElementById('clientPickerPanel');
            const searchInput = document.getElementById('clientPickerSearch');
            const labelEl = document.getElementById('clientPickerLabel');
            const items = Array.from(document.querySelectorAll('#clientPickerList .client-picker-item'));
            const modalBackdrop = document.getElementById('passwordModalBackdrop');
            const openPasswordControls = document.getElementById('openPasswordControls');
            const closePasswordControls = document.getElementById('closePasswordControls');

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

            function hidePasswordModal() {
                if (!modalBackdrop) return;
                modalBackdrop.setAttribute('hidden', 'hidden');
                document.body.classList.remove('dd-account-modal-open');
            }

            function showPasswordModal() {
                if (!modalBackdrop) return;
                modalBackdrop.removeAttribute('hidden');
                document.body.classList.add('dd-account-modal-open');
            }

            if (openPasswordControls) {
                openPasswordControls.addEventListener('click', showPasswordModal);
            }

            const queryParams = new URLSearchParams(window.location.search);
            if (queryParams.get('password') === '1') {
                showPasswordModal();
            }

            if (closePasswordControls) {
                closePasswordControls.addEventListener('click', hidePasswordModal);
            }

            if (modalBackdrop) {
                modalBackdrop.addEventListener('click', function (event) {
                    if (event.target === modalBackdrop) {
                        hidePasswordModal();
                    }
                });
            }

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    hidePasswordModal();
                }
            });

        })();
    </script>
@endsection
