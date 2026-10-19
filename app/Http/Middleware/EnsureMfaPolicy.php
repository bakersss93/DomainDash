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

        $allowedRouteNames = [
            'profile.show',
            'user-profile-information.update',
            'user-password.update',
            'two-factor.*',
            'logout',
            'admin.users.stop-impersonate',
            'me.mfa.*',
            'me.account.details',
            'password.confirm',
            'password.confirmation',
        ];

        foreach ($allowedRouteNames as $routeName) {
            if ($request->routeIs($routeName)) {
                return $next($request);
            }
        }

        if ($request->is('livewire/*')) {
            return $next($request);
        }

        // Mandatory MFA (e.g. Administrator): block everything except the setup
        // flow until 2FA is confirmed. The dashboard stays reachable because it
        // hosts the (non-dismissible) setup prompt.
        if ($user->requiresMfa() && ! $user->hasConfiguredMfa()) {
            $request->session()->put('mfa.setup', ['show' => true, 'required' => true]);

            if ($request->routeIs('dashboard')) {
                return $next($request);
            }

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Multi-factor authentication setup is required.'], 403);
            }

            return redirect()->route('dashboard')->with('status', 'Multi-factor authentication is required for your role. Please complete setup.');
        }

        if ($user->hasConfiguredMfa() || $user->mfa_preference === 'disabled') {
            $request->session()->forget('mfa.setup');

            return $next($request);
        }

        if ($user->mfa_preference === 'enforced') {
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
}
