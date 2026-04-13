<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="dd-login-surface">
            <x-validation-errors class="mb-4" />

            @session('status')
                <div class="mb-4 font-medium text-sm text-green-600 dark:text-green-400">
                    {{ $value }}
                </div>
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
                        <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Remember me') }}</span>
                    </label>
                </div>

                <div class="flex items-center justify-end mt-4 gap-2">
                    @if (Route::has('password.request'))
                        <a class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800" href="{{ route('password.request') }}">
                            {{ __('Forgot your password?') }}
                        </a>
                    @endif

                    <button type="submit" class="btn-accent dd-login-submit">
                        {{ __('Log in') }}
                    </button>
                </div>
            </form>
        </div>
    </x-authentication-card>

    <style>
        .dd-login-surface .dd-field {
            background: #ffffff;
            color: #111827;
            border-color: #cbd5e1;
            border-radius: 12px;
        }

        .dd-login-surface .dd-field::placeholder {
            color: #6b7280;
        }

        .dd-login-surface .dd-btn,
        .dd-login-submit {
            border-radius: 12px;
        }


        .dd-password-wrap {
            position: relative;
        }

        .dd-password-toggle {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #f8fafc;
            color: #0f172a;
            padding: 4px 10px;
            font-size: 0.75rem;
            font-weight: 600;
            line-height: 1;
        }

        .dd-login-surface .dd-checkbox {
            appearance: none;
            width: 1rem;
            height: 1rem;
            border-radius: 6px;
            border: 1px solid #94a3b8;
            background: #ffffff;
            display: inline-grid;
            place-content: center;
        }

        .dd-login-surface .dd-checkbox:checked {
            background: #0284c7;
            border-color: #0284c7;
        }

        .dd-login-surface .dd-checkbox:checked::before {
            content: "";
            width: 0.35rem;
            height: 0.35rem;
            border-radius: 2px;
            background: #ffffff;
        }
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
