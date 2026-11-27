<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;

class ApplyImpersonation
{
    public function handle(Request $request, Closure $next)
    {
        if (session()->has('impersonate_as')) {
            $user = User::find(session('impersonate_as'));
            if ($user) {
                auth()->setUser($user);
            }
        }
        return $next($request);
    }
}
