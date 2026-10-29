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
        </div>
    </x-authentication-card>

    <style>
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
