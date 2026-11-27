@extends('layouts.app')

@section('content')
    <div class="dd-account-wrap">
        <section class="dd-account-card">
            <header class="dd-account-header">
                <h1>Create User</h1>
                <p>Set up a new profile with role permissions and client access in one place.</p>
            </header>

            <div class="dd-account-grid">
                <form id="user-create-form" method="POST" action="{{ route('admin.users.store') }}" class="dd-account-main-form">
                    @csrf

                    <h2 class="dd-account-section-title">Personal Information</h2>

                    <div class="dd-account-row">
                        <div class="dd-account-field">
                            <label for="first_name">First Name</label>
                            <input class="dd-account-input" id="first_name" name="first_name" type="text" required value="{{ old('first_name') }}">
                            @error('first_name')<div class="ui-error" role="alert" style="margin-top:0.25rem;">{{ $message }}</div>@enderror
                        </div>

                        <div class="dd-account-field">
                            <label for="last_name">Last Name</label>
                            <input class="dd-account-input" id="last_name" name="last_name" type="text" required value="{{ old('last_name') }}">
                            @error('last_name')<div class="ui-error" role="alert" style="margin-top:0.25rem;">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="dd-account-field">
                        <label for="email">Email Address</label>
                        <input class="dd-account-input" id="email" name="email" type="email" required value="{{ old('email') }}">
                        @error('email')<div class="ui-error" role="alert" style="margin-top:0.25rem;">{{ $message }}</div>@enderror
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
                        @error('role')<div class="ui-error" role="alert" style="margin-top:0.25rem;">{{ $message }}</div>@enderror
                    </div>


                    <div class="dd-account-field dd-account-toggle-field">
                        <input type="hidden" name="is_active" value="0">
                        <label class="dd-account-checkbox-row">
                            <input class="dd-account-checkbox" type="checkbox" name="is_active" value="1" {{ old('is_active', 1) ? 'checked' : '' }}>
                            <span>Enable user account</span>
                        </label>
                        <small class="dd-account-help-text">Disabled users cannot log in.</small>
                        @error('is_active')<div class="ui-error" role="alert" style="margin-top:0.25rem;">{{ $message }}</div>@enderror
                    </div>

                    <div class="dd-account-field">
                        <label for="mfa_preference">MFA Policy</label>
                        <select class="dd-account-input" id="mfa_preference" name="mfa_preference" required>
                            <option value="enabled" {{ old('mfa_preference', 'enabled') === 'enabled' ? 'selected' : '' }}>Enabled (user can configure)</option>
                            <option value="enforced" {{ old('mfa_preference') === 'enforced' ? 'selected' : '' }}>Enforced (required)</option>
                            <option value="disabled" {{ old('mfa_preference') === 'disabled' ? 'selected' : '' }}>Disabled</option>
                        </select>
                        @error('mfa_preference')<div class="ui-error" role="alert" style="margin-top:0.25rem;">{{ $message }}</div>@enderror
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
                                            <input class="client-picker-checkbox" type="checkbox" name="client_ids[]" value="{{ $client->id }}" {{ in_array($client->id, old('client_ids', [])) ? 'checked' : '' }}>
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
                        <button type="submit" class="btn-accent">Save user</button>
                        <button type="button" class="dd-account-secondary" onclick="window.location.href='{{ route('admin.users') }}'">Cancel</button>
                    </div>
                </form>

                <aside class="dd-account-aside">
                    <h3>Password & Security</h3>
                    <p>Choose how to provision the account credentials.</p>
                    <ul>
                        <li>Set a password manually before creating the user.</li>
                        <li>Leave password blank to auto-generate credentials.</li>
                        <li>Share access details securely after creation.</li>
                    </ul>
                    <button type="button" class="dd-account-password-btn" id="openPasswordSetup">Set Password</button>
                </aside>
            </div>
        </section>
    </div>

    <div class="dd-account-modal-backdrop" id="passwordSetupModalBackdrop" hidden>
        <div class="dd-account-modal" role="dialog" aria-modal="true" aria-labelledby="passwordSetupModalTitle">
            <div class="dd-account-modal-header">
                <h2 id="passwordSetupModalTitle">Password Setup</h2>
                <button type="button" class="dd-account-modal-close" id="closePasswordSetup" aria-label="Close password setup">×</button>
            </div>
            <p class="dd-account-modal-intro">Set an optional password now, or close this modal to keep auto-generated credentials.</p>

            <div class="dd-account-modal-grid">
                <div class="dd-account-modal-form">
                    <h3>Set Password</h3>
                    <div class="dd-account-field">
                        <label for="password">Password (optional)</label>
                        <input class="dd-account-input" id="password" name="password" type="password" form="user-create-form" value="{{ old('password') }}">
                    </div>
                    <div class="dd-account-field">
                        <label for="password_confirmation">Confirm Password</label>
                        <input class="dd-account-input" id="password_confirmation" name="password_confirmation" type="password" form="user-create-form" value="{{ old('password_confirmation') }}">
                    </div>
                    @error('password')<div class="ui-error" role="alert" style="margin-top:0.25rem;">{{ $message }}</div>@enderror
                </div>
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
            const modalBackdrop = document.getElementById('passwordSetupModalBackdrop');
            const openPasswordSetup = document.getElementById('openPasswordSetup');
            const closePasswordSetup = document.getElementById('closePasswordSetup');
            const hasPasswordErrors = {{ $errors->has('password') || $errors->has('password_confirmation') ? 'true' : 'false' }};

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

            function hidePasswordSetupModal() {
                if (!modalBackdrop) return;
                modalBackdrop.setAttribute('hidden', 'hidden');
                document.body.classList.remove('dd-account-modal-open');
            }

            function showPasswordSetupModal() {
                if (!modalBackdrop) return;
                modalBackdrop.removeAttribute('hidden');
                document.body.classList.add('dd-account-modal-open');
            }

            if (openPasswordSetup) {
                openPasswordSetup.addEventListener('click', showPasswordSetupModal);
            }

            if (closePasswordSetup) {
                closePasswordSetup.addEventListener('click', hidePasswordSetupModal);
            }

            if (modalBackdrop) {
                modalBackdrop.addEventListener('click', function (event) {
                    if (event.target === modalBackdrop) {
                        hidePasswordSetupModal();
                    }
                });
            }

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    hidePasswordSetupModal();
                }
            });

            if (hasPasswordErrors) {
                showPasswordSetupModal();
            }
        })();
    </script>
@endsection
