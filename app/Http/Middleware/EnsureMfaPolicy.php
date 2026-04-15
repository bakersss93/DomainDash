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
            'me.mfa.*',
        ];

        foreach ($allowedRouteNames as $routeName) {
            if ($request->routeIs($routeName)) {
                return $next($request);
            }
        }

        if ($request->is('livewire/*')) {
            return $next($request);
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
