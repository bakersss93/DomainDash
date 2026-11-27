<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;

/**
 * SSO identity accepted but a local MFA challenge was required: the SSO controllers park the identity in
 * `auth.via_pending` (with the user id). When Fortify then completes the two-factor challenge for that same user,
 * promote it to the real `auth.via` + identity keys so the per-request SSO revalidation (EnsureMfaPolicy) applies.
 * Any other login (e.g. a password login after an abandoned SSO attempt) just discards the pending state.
 */
class PromotePendingSsoSession
{
    public function handle(Login $event): void
    {
        if (! app()->bound('request') || ! request()->hasSession()) {
            return;
        }
        $session = request()->session();
        $pending = $session->get('auth.via_pending');
        if ($pending === null) {
            return;
        }
        $session->forget('auth.via_pending');

        if (! is_array($pending)
            || ! request()->routeIs('two-factor.login.store')
            || (int) ($pending['user_id'] ?? 0) !== (int) $event->user->getAuthIdentifier()
            || ! in_array($pending['via'] ?? null, ['saml', 'microsoft'], true)) {
            return;
        }

        $session->put(['auth.via' => $pending['via'], 'auth.mfa_satisfied' => false] + (array) ($pending['keys'] ?? []));
    }
}
