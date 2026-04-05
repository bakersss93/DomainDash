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

                    <div class="dd-account-actions" style="display:flex;justify-content:flex-start;gap:12px;margin-top:1.6rem;padding-top:1rem;border-top:1px solid #334155;">
                        <button type="submit" class="btn-accent">Save user</button>
                        <button type="button" class="dd-account-secondary" style="min-width:130px;border:1px solid #4b637f;background:#1d2d45;color:#f8fafc;border-radius:9999px;padding:10px 16px;" onclick="window.location.href='{{ route('admin.users') }}'">Cancel</button>
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
                    @error('password')<div style="color:#dc2626;font-size:12px;margin-top:4px;">{{ $message }}</div>@enderror
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
            align-items: start !important;
        }

        .dd-account-grid > form {
            min-width: 0;
        }

        .dd-account-aside {
            align-self: start;
            border: 1px solid #334155 !important;
            background: #111827 !important;
            border-radius: 14px !important;
            padding: 16px !important;
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

        .dd-account-field select,
        .client-picker-toggle {
            appearance: none !important;
            background-image: linear-gradient(45deg, transparent 50%, #93a9c8 50%), linear-gradient(135deg, #93a9c8 50%, transparent 50%) !important;
            background-position: calc(100% - 18px) calc(50% - 3px), calc(100% - 12px) calc(50% - 3px) !important;
            background-size: 6px 6px, 6px 6px !important;
            background-repeat: no-repeat !important;
            padding-right: 34px !important;
        }

        .client-picker-toggle {
            border: 1px solid #334155 !important;
            border-radius: 12px !important;
            background-color: #1e293b !important;
            color: #f8fafc !important;
            min-height: 46px !important;
            display: flex !important;
            align-items: center !important;
        }

        .client-picker-arrow {
            display: none !important;
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

        .dd-account-aside p {
            margin-bottom: 0.55rem !important;
        }

        .dd-account-aside ul {
            margin: 0 !important;
            padding-left: 1rem !important;
        }

        .dd-account-password-btn {
            border: 1px solid #4b637f !important;
            background: #1d2d45 !important;
            color: #f8fafc !important;
            border-radius: 10px !important;
            padding: 9px 12px !important;
            text-decoration: none !important;
            margin-top: 0.3rem !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
        }

        .dd-account-modal-open {
            overflow: hidden;
        }

        .dd-account-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(2, 6, 23, 0.75);
            display: grid;
            place-items: center;
            z-index: 80;
            padding: 18px;
        }

        .dd-account-modal-backdrop[hidden] {
            display: none !important;
        }

        .dd-account-modal {
            width: min(900px, 100%);
            border: 1px solid #334155;
            border-radius: 16px;
            background: #0f172a;
            box-shadow: 0 24px 44px rgba(2, 6, 23, 0.6);
            padding: 20px;
        }

        .dd-account-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.35rem;
        }

        .dd-account-modal-header h2,
        .dd-account-modal h3 {
            margin: 0;
            color: #f8fafc;
        }

        .dd-account-modal-intro,
        .dd-account-modal p {
            color: #a8b4c8;
        }

        .dd-account-modal-close {
            border: 1px solid #334155;
            background: #1e293b;
            color: #f8fafc;
            border-radius: 8px;
            width: 36px;
            height: 36px;
            font-size: 22px;
            line-height: 1;
        }

        .dd-account-modal-grid {
            margin-top: 1rem;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .dd-account-modal-form {
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 14px;
            background: #111827;
        }

        @media (max-width: 1024px) {
            .dd-account-grid,
            .dd-account-row,
            .dd-account-modal-grid {
                grid-template-columns: 1fr !important;
            }
        }
    </style>
@endsection
