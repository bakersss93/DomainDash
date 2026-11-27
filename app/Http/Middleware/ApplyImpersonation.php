<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;
use App\Services\AuditLogger;

class ApplyImpersonation
{
    public function handle(Request $request, Closure $next)
    {
        if (session()->has('impersonate_as')) {
            $real = auth()->user();
            $target = User::find(session('impersonate_as'));
            $reason = $this->invalidReason($real, $target);

            if ($reason === null) {
                auth()->setUser($target);
            } else {
                // Fall back to the real session user; the impersonation is over.
                session()->forget(['impersonate_as', 'impersonator_id', 'impersonation_started_at']);

                AuditLogger::logSystem('impersonation.end', "Impersonation ended automatically: {$reason}.", [
                    'service' => 'auth',
                    'function' => 'impersonation',
                    'impersonator_id' => $real?->id,
                    'impersonated_user_id' => $target?->id,
                ]);
            }
        }

        return $next($request);
    }

    /** Re-validated on every request, not just when impersonation starts. */
    private function invalidReason(?User $real, ?User $target): ?string
    {
        if (! $real || $real->is_active === false || ! $real->hasRole('Administrator')) {
            return 'the impersonator is no longer an active administrator';
        }

        $recorded = session('impersonator_id');
        if ($recorded !== null && (int) $recorded !== (int) $real->id) {
            return 'the session user is not the recorded impersonator';
        }

        if (! $target || ! $target->is_active || $target->hasRole('Administrator')) {
            return 'the target user is no longer eligible';
        }

        // Sessions that predate the time limit start their window now.
        $startedAt = session('impersonation_started_at');
        if ($startedAt === null) {
            session(['impersonation_started_at' => now()->getTimestamp()]);
        } elseif (now()->getTimestamp() - (int) $startedAt > max(1, (int) config('domaindash.impersonation.max_minutes', 60)) * 60) {
            return 'the maximum impersonation time was reached';
        }

        return null;
    }
}
