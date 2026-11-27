<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureMfaPolicy
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // Deactivated users lose existing sessions immediately.
        if ($user->is_active === false) {
            \Illuminate\Support\Facades\Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['message' => 'This user is disabled.'], 403)
                : redirect()->route('login');
        }

        // Fortify's two-factor management endpoints are not used by DomainDash (enrolment and
        // re-enrolment live in /me/mfa/* and /me/account/mfa-reenroll, which verify the current
        // code). Left reachable they would let a hijacked session swap or disable the TOTP secret.
        if ($request->routeIs([
            'two-factor.enable',
            'two-factor.confirm',
            'two-factor.disable',
            'two-factor.qr-code',
            'two-factor.secret-key',
            'two-factor.recovery-codes',
            'two-factor.regenerate-recovery-codes',
        ])) {
            return response()->json(['message' => 'Manage multi-factor authentication from Account Settings.'], 403);
        }

        // Only the MFA enrolment flow, logout and (for the impersonation banner) stop-impersonate
        // are reachable while MFA setup is outstanding. Profile / Fortify / team routes are NOT
        // exempt: they are covered by the same policy.
        $allowedRouteNames = [
            'logout',
            'admin.users.stop-impersonate',
            'me.mfa.*',
            'me.account.details',
            'password.confirm',
            'password.confirm.store',
            'password.confirmation',
        ];

        foreach ($allowedRouteNames as $routeName) {
            if ($request->routeIs($routeName)) {
                return $next($request);
            }
        }

        // Mandatory MFA (required role, or per-user "enforced" preference): block EVERYTHING except
        // the setup flow until 2FA is confirmed. The dashboard URL renders only the setup page.
        // An administrator impersonating someone has already passed their own MFA.
        if ($this->mustEnrol($user) && ! $request->session()->has('impersonate_as')) {
            $request->session()->put('mfa.setup', ['show' => true, 'required' => true]);

            if ($request->expectsJson() || $request->is('api/*') || $request->is('livewire/*')) {
                return response()->json(['message' => 'Multi-factor authentication setup is required.'], 403);
            }

            if ($request->isMethod('GET') && $request->routeIs('dashboard')) {
                return response()->view('auth.mfa-required');
            }

            return redirect()->route('dashboard')->with('status', 'Multi-factor authentication is required for your account. Please complete setup.');
        }

        if ($user->hasConfiguredMfa() || $user->mfa_preference === 'disabled') {
            $request->session()->forget('mfa.setup');

            return $next($request);
        }

        if ($user->mfa_preference === 'enforced') {
            // Only reached when MFA_ENFORCE=false (break-glass): prompt but do not block.
            $request->session()->put('mfa.setup', [
                'show' => true,
                'required' => true,
            ]);

            return $next($request);
        }

        $nextPromptAt = optional($user->mfa_prompted_at)->addDays(7);
        $shouldPrompt = ! $nextPromptAt || now()->greaterThanOrEqualTo($nextPromptAt);

        if ($shouldPrompt) {
            $request->session()->put('mfa.setup', [
                'show' => true,
                'required' => false,
            ]);
        } else {
            $request->session()->forget('mfa.setup');
        }

        return $next($request);
    }

    private function mustEnrol($user): bool
    {
        if ($user->hasConfiguredMfa()) {
            return false;
        }

        if ($user->requiresMfa()) {
            return true;
        }

        return config('domaindash.mfa.enforce', true) && $user->mfa_preference === 'enforced';
    }
}
