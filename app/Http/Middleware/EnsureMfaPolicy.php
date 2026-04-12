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

        $allowedRouteNames = [
            'profile.show',
            'user-profile-information.update',
            'user-password.update',
            'two-factor.*',
            'logout',
            'admin.users.stop-impersonate',
        ];

        foreach ($allowedRouteNames as $routeName) {
            if ($request->routeIs($routeName)) {
                return $next($request);
            }
        }

        if ($request->is('livewire/*')) {
            return $next($request);
        }

        if ($user->mfa_preference === 'enforced' && ! $user->hasConfiguredMfa()) {
            return redirect()
                ->route('profile.show')
                ->with('error', 'Two-factor authentication is required for your account. Please set up MFA before continuing.');
        }

        return $next($request);
    }
}
