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
            return $this->endSession($request, 'This user is disabled.');
        }

        // SSO sessions are re-validated on every request: the connection must still exist and be enabled, and the
        // user must still be bound to it. Otherwise disabling a connection / unlinking a user ends the session.
        $samlConnection = null;
        if ($request->session()->get('auth.via') === 'saml') {
            $samlConnection = \App\Models\SamlConnection::query()->find($request->session()->get('auth.saml_connection_id'));

            if (! $samlConnection || ! $samlConnection->enabled
                || (int) $user->saml_connection_id !== (int) $samlConnection->id) {
                return $this->endSession($request, 'Your single sign-on session is no longer valid.');
            }
        }

        // Microsoft (client OIDC) sessions are re-validated on every request: the feature must still be on and the
        // user must still be the same non-staff, still-linked Microsoft identity that signed in.
        $viaMicrosoft = $request->session()->get('auth.via') === 'microsoft';
        if ($viaMicrosoft
            && (! \App\Services\Microsoft\MicrosoftSettings::configured()
                || $user->microsoft_oid === null
                || $user->microsoft_oid !== $request->session()->get('auth.microsoft_oid')
                || $user->microsoft_tid !== $request->session()->get('auth.microsoft_tid')
                || \App\Services\Saml\SsoPolicy::isStaff($user))) {
            return $this->endSession($request, 'Your Microsoft sign-in session is no longer valid.');
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

        // Entra ID already enforced MFA / conditional access for this SSO session (connection setting),
        // which also satisfies the mandatory-MFA policy below.
        // The bypass only holds while the connection still says to trust Entra for MFA.
        if ($samlConnection && $request->session()->get('auth.mfa_satisfied') && $samlConnection->skip_local_mfa) {
            $request->session()->forget('mfa.setup');

            return $next($request);
        }

        // Same for Microsoft sign-in, but only while "Trust Microsoft sign-in for MFA (clients)" is still on.
        if ($viaMicrosoft && $request->session()->get('auth.mfa_satisfied') && \App\Services\Microsoft\MicrosoftSettings::trustMfa()) {
            $request->session()->forget('mfa.setup');

            return $next($request);
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

    private function endSession(Request $request, string $message)
    {
        \Illuminate\Support\Facades\Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->expectsJson()
            ? response()->json(['message' => $message], 403)
            : redirect()->route('login');
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
