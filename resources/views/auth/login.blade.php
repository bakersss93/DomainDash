<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="dd-login-surface">
            <x-validation-errors class="mb-4" />

            @session('status')
                <x-ui.alert variant="success" class="mb-4">{{ $value }}</x-ui.alert>
            @endsession

            <form method="POST" action="{{ route('login') }}">
                @csrf

                <div>
                    <x-label for="email" value="{{ __('Email') }}" />
                    <x-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
                </div>

                <div class="mt-4">
                    <x-label for="password" value="{{ __('Password') }}" />
                    <div class="dd-password-wrap mt-1">
                        <x-input id="password" class="block w-full pe-20" type="password" name="password" required autocomplete="current-password" />
                        <button type="button" class="dd-password-toggle" id="togglePassword" aria-controls="password" aria-label="Toggle password visibility">Show</button>
                    </div>
                </div>

                <div class="block mt-4">
                    <label for="remember_me" class="flex items-center">
                        <x-checkbox id="remember_me" name="remember" />
                        <span class="ms-2 text-sm text-muted">{{ __('Remember me') }}</span>
                    </label>
                </div>

                <div class="flex items-center justify-end mt-4 gap-2">
                    @if (Route::has('password.request'))
                        <a class="ui-link text-sm" href="{{ route('password.request') }}">
                            {{ __('Forgot your password?') }}
                        </a>
                    @endif

                    <button type="submit" class="ui-btn ui-btn--primary">
                        {{ __('Log in') }}
                    </button>
                </div>
            </form>

            @if (\App\Services\Microsoft\MicrosoftSettings::configured() || \App\Models\SamlConnection::query()->where('enabled', true)->exists())
                <div class="dd-sso-divider" role="separator"><span>or</span></div>
                <form method="POST" action="{{ route('sso.discover') }}" class="dd-sso-form">
                    @csrf
                    <x-label for="sso_email" value="{{ __('Work email') }}" />
                    <x-input id="sso_email" class="block mt-1 w-full" type="email" name="email" autocomplete="username" placeholder="you@company.com" />
                    <p class="mt-1 text-xs text-muted">{{ __('Type your work email first (or in the field above) so we can choose the right Microsoft sign-in.') }}</p>
                    @error('sso_email') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    <button type="submit" class="dd-sso-btn">
                        <svg width="18" height="18" viewBox="0 0 21 21" aria-hidden="true"><rect x="1" y="1" width="9" height="9" fill="#f25022"/><rect x="11" y="1" width="9" height="9" fill="#7fba00"/><rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/></svg>
                        Sign in with Microsoft
                    </button>
                </form>
            @endif
        </div>
    </x-authentication-card>

    <style>
        .dd-sso-divider { display:flex; align-items:center; gap:12px; margin:20px 0 12px; color:#64748b; font-size:0.8rem; }
        .dd-sso-divider::before, .dd-sso-divider::after { content:""; flex:1; border-top:1px solid #cbd5e1; }
        .dd-sso-btn { display:flex; align-items:center; justify-content:center; gap:10px; width:100%; margin-top:12px; padding:10px 14px; border:1px solid #8c8c8c; border-radius:12px; background:#fff; color:#1f1f1f; font-weight:600; font-size:0.9rem; cursor:pointer; }
        .dd-sso-btn:hover { background:#f3f4f6; }
        .dd-password-wrap { position: relative; }
        .dd-password-toggle {
            position: absolute;
            right: 0.5rem;
            top: 50%;
            transform: translateY(-50%);
            border: 1px solid var(--dd-border-strong);
            border-radius: var(--dd-radius-sm);
            background: var(--dd-surface-muted);
            color: var(--dd-text);
            padding: 0.25rem 0.625rem;
            font-size: 0.75rem;
            font-weight: 600;
            line-height: 1.2;
            cursor: pointer;
        }
        .dd-password-toggle:hover { background: var(--dd-border); }
    </style>

    <script>
        (function () {
            // One typed email serves both forms: the Microsoft button reuses what was entered above.
            const main = document.getElementById('email');
            const sso = document.getElementById('sso_email');
            if (main && sso) {
                if (!sso.value) sso.value = main.value;
                main.addEventListener('input', function () { sso.value = main.value; });
            }
        })();

        (function () {
            const passwordInput = document.getElementById('password');
            const toggleButton = document.getElementById('togglePassword');

            if (!passwordInput || !toggleButton) {
                return;
            }

            toggleButton.addEventListener('click', function () {
                const showingPassword = passwordInput.type === 'text';
                passwordInput.type = showingPassword ? 'password' : 'text';
                toggleButton.textContent = showingPassword ? 'Show' : 'Hide';
            });
        })();
    </script>
</x-guest-layout>
