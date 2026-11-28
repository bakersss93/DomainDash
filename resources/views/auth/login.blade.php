<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        @php
            $state = $state ?? 'email';
            $brand = $brand ?? config('app.name', 'DomainDash');
            $v = fn (string $states) => 'data-v="'.$states.'"'.(in_array($state, explode(' ', $states), true) ? '' : ' disabled');
            $show = fn (string $states) => 'data-v="'.$states.'"';
            $readonly = $state !== 'email';
        @endphp
        <div class="dd-login-surface">
            <x-validation-errors class="mb-4" />

            @session('status')
                <x-ui.alert variant="success" class="mb-4">{{ $value }}</x-ui.alert>
            @endsession

            {{--
                One form, five server-rendered states (email, password, sso, sso-both, password-sso). CSS shows what belongs to the
                state; without JavaScript every switch is a normal link/button that reloads the page. With JavaScript, typing
                an email looks the method up (POST login.method) and switches the state in place.
            --}}
            <form method="POST" action="{{ route('login') }}" class="dd-login" id="loginForm" data-state="{{ $state }}"
                  data-method-url="{{ route('login.method') }}" data-password-url="{{ route('login.mode', 'password') }}" data-sso-url="{{ route('login.mode', 'sso') }}">
                @csrf

                <h1 class="dd-login-heading" {!! $show('email password password-sso') !!}>{{ __('Log in to :name', ['name' => $brand]) }}</h1>
                <h1 class="dd-login-heading" {!! $show('sso sso-both') !!}>{{ __('Single sign-on') }}</h1>

                <div>
                    <x-label for="email" value="{{ __('Email') }}" />
                    <x-input id="email" class="block mt-1 w-full" type="email" name="email" :value="$email !== '' ? $email : old('email')" :readonly="$readonly" required :autofocus="$state === 'email'" autocomplete="username" />
                    <a class="ui-link text-xs mt-1 inline-block dd-nojs" href="{{ route('login.reset') }}" {!! $show('password sso sso-both password-sso') !!}>{{ __('Use a different email') }}</a>
                </div>

                <button type="submit" id="btnContinue" class="ui-btn ui-btn--primary ui-btn--block mt-4" formaction="{{ route('login.identify') }}" formnovalidate {!! $v('email') !!}>{{ __('Continue') }}</button>

                <div class="mt-4" {!! $show('password password-sso') !!}>
                    <div class="flex items-center justify-between">
                        <x-label for="password" value="{{ __('Password') }}" />
                        @if (Route::has('password.request'))
                            <a class="ui-link text-xs" href="{{ route('password.request') }}">{{ __('Forgot password?') }}</a>
                        @endif
                    </div>
                    <div class="dd-password-wrap mt-1">
                        <x-input id="password" class="block w-full pe-20" type="password" name="password" required autocomplete="current-password" data-v="password password-sso" :disabled="! in_array($state, ['password', 'password-sso'], true)" />
                        <button type="button" class="dd-password-toggle" id="togglePassword" aria-controls="password" aria-label="Toggle password visibility">Show</button>
                    </div>
                </div>

                <button type="submit" id="btnMicrosoft" class="dd-sso-btn" formaction="{{ route('login.sso') }}" formnovalidate {!! $v('sso sso-both') !!}>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 9.8-9.8"/><path d="m17 6 3 3"/><path d="m14.5 8.5 2.5 2.5"/></svg>
                    {{ __('Log in with Microsoft') }}
                </button>

                <div class="block mt-4" {!! $show('password sso sso-both password-sso') !!}>
                    <label for="remember_me" class="flex items-center">
                        <x-checkbox id="remember_me" name="remember" value="1" :disabled="$state === 'email'" data-v="password sso sso-both password-sso" />
                        <span class="ms-2 text-sm text-muted">{{ __('Keep me logged in') }}</span>
                    </label>
                </div>

                <button type="submit" id="btnLoginPw" class="ui-btn ui-btn--primary ui-btn--block mt-4" {!! $v('password password-sso') !!}>{{ __('Log in') }}</button>
                <button type="submit" id="btnLoginSso" class="ui-btn ui-btn--primary ui-btn--block mt-4" formaction="{{ route('login.sso') }}" formnovalidate {!! $v('sso sso-both') !!}>{{ __('Log in') }}</button>

                <div class="mt-4 text-center" {!! $show('sso sso-both') !!}>
                    @if (Route::has('password.request'))
                        <a class="ui-link text-sm" href="{{ route('password.request') }}">{{ __('Forgot password?') }}</a>
                    @endif
                </div>
                <div class="mt-3 text-center" {!! $show('sso-both') !!}>
                    <a class="ui-link text-sm" id="linkUsePassword" href="{{ route('login.mode', 'password') }}">{{ __('Use password instead') }}</a>
                </div>

                <div class="dd-sso-divider" role="separator" {!! $show('password-sso') !!}><span>{{ __('Or') }}</span></div>
                <button type="submit" id="btnSsoSecondary" class="dd-sso-btn dd-sso-btn--outline" formaction="{{ route('login.sso') }}" formnovalidate {!! $v('password-sso') !!}>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 4 6v6c0 4.5 3.2 7.8 8 9 4.8-1.2 8-4.5 8-9V6l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg>
                    {{ __('Log In with Single Sign-On') }}
                </button>
                <div class="mt-3 text-center" {!! $show('password-sso') !!}>
                    <a class="ui-link text-sm" id="linkBack" href="{{ route('login.mode', 'sso') }}">{{ __('Back') }}</a>
                </div>
            </form>
        </div>
    </x-authentication-card>

    <style>
        .dd-login [data-v] { display:none; }
        .dd-login[data-state="email"] [data-v~="email"],
        .dd-login[data-state="password"] [data-v~="password"],
        .dd-login[data-state="sso"] [data-v~="sso"],
        .dd-login[data-state="sso-both"] [data-v~="sso-both"],
        .dd-login[data-state="password-sso"] [data-v~="password-sso"] { display:var(--dd-show, block); }
        .dd-login [data-v].dd-sso-btn, .dd-login [data-v].dd-flex { --dd-show:flex; }
        .dd-login [data-v~="password"].ui-btn, .dd-login [data-v~="sso"].ui-btn, .dd-login [data-v~="email"].ui-btn { --dd-show:flex; }
        .dd-login[data-js] .dd-nojs { display:none !important; }
        .dd-login-heading { margin:0 0 1rem; font-size:1.125rem; font-weight:600; text-align:center; color:var(--dd-text); }
        .dd-sso-divider { align-items:center; gap:12px; margin:20px 0 4px; color:var(--dd-text-soft); font-size:0.8rem; --dd-show:flex; }
        .dd-sso-divider::before, .dd-sso-divider::after { content:""; flex:1; border-top:1px solid var(--dd-border); }
        .dd-sso-btn { align-items:center; justify-content:center; gap:10px; width:100%; margin-top:16px; padding:0.5rem 0.875rem; border:1px solid var(--dd-border-strong); border-radius:var(--dd-radius-sm, 0.5rem); background:var(--dd-surface-muted); color:var(--dd-text); font-weight:600; font-size:0.875rem; cursor:pointer; }
        .dd-sso-btn--outline { background:transparent; }
        .dd-sso-btn:hover { background:var(--dd-border); }
        input[readonly]#email { background:var(--dd-surface-muted); }
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
            if (!passwordInput || !toggleButton) return;
            toggleButton.addEventListener('click', function () {
                const showing = passwordInput.type === 'text';
                passwordInput.type = showing ? 'password' : 'text';
                toggleButton.textContent = showing ? 'Show' : 'Hide';
            });
        })();

        // Progressive enhancement: look the sign-in method up while the email is typed and switch the view in place.
        (function () {
            const form = document.getElementById('loginForm');
            const email = document.getElementById('email');
            if (!form || !email || form.dataset.state !== 'email' || !window.fetch) return;
            form.setAttribute('data-js', '1');

            const csrf = form.querySelector('input[name="_token"]').value;
            const looksLikeEmail = (v) => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v);
            let timer = null, seq = 0, lastAsked = '', ssoPassword = false, state = 'email';

            function setState(next) {
                state = next;
                form.dataset.state = next;
                form.querySelectorAll('[data-v]').forEach(function (el) {
                    if (!('disabled' in el)) return;
                    el.disabled = el.dataset.v.split(' ').indexOf(next) === -1;
                });
            }

            function lookup() {
                const value = email.value.trim().toLowerCase();
                if (!looksLikeEmail(value)) { lastAsked = ''; setState('email'); return; }
                if (value === lastAsked) return;
                lastAsked = value;
                const mine = ++seq;
                fetch(form.dataset.methodUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ email: value }),
                    credentials: 'same-origin'
                }).then(function (r) { return r.ok ? r.json() : { method: 'password' }; })
                  .catch(function () { return null; })
                  .then(function (res) {
                      if (mine !== seq || res === null) return; // stale answer, or offline: stay on the server-rendered step
                      ssoPassword = !!res.password;
                      setState(res.method === 'sso' ? (ssoPassword ? 'sso-both' : 'sso') : 'password');
                      if (res.method !== 'sso') { /* keep typing in the email field; password appears below */ }
                  });
            }

            function schedule() { clearTimeout(timer); timer = setTimeout(lookup, 450); }
            email.addEventListener('input', function () { lastAsked = ''; schedule(); });
            email.addEventListener('blur', function () { clearTimeout(timer); lookup(); });

            // Which button Enter presses in each view: both password views use the password login button.
            const pwButton = 'btnLoginPw';
            const primary = { email: 'btnContinue', password: pwButton, 'password-sso': pwButton, sso: 'btnLoginSso', 'sso-both': 'btnLoginSso' };
            form.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter' || e.target.tagName !== 'INPUT' || e.target.type === 'checkbox') return;
                e.preventDefault();
                if (state === 'email') { lookup(); return; }
                document.getElementById(primary[state]).click();
            });

            document.getElementById('linkUsePassword').addEventListener('click', function (e) { e.preventDefault(); setState('password-sso'); document.getElementById('password').focus(); });
            document.getElementById('linkBack').addEventListener('click', function (e) { e.preventDefault(); setState('sso-both'); });

            setState('email');
            if (looksLikeEmail(email.value.trim())) lookup();
        })();
    </script>
</x-guest-layout>
