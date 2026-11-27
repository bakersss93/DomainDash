<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SamlConnectionDomain;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Microsoft\MicrosoftIdentity;
use App\Services\Microsoft\MicrosoftOidcClient;
use App\Services\Microsoft\MicrosoftOidcException;
use App\Services\Microsoft\MicrosoftSettings;
use App\Services\Microsoft\MicrosoftUserResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/** "Sign in with Microsoft" for client users (OIDC) plus the email-first router that sends staff to SAML. */
class MicrosoftController extends Controller
{
    public function __construct(private MicrosoftOidcClient $oidc, private MicrosoftUserResolver $resolver)
    {
    }

    /** Login page button: the typed email decides the method (empty email = client sign-in without a hint). */
    public function discover(Request $request)
    {
        $data = $request->validate(['email' => 'nullable|string|max:254']);

        return $this->route($request, strtolower(trim((string) ($data['email'] ?? ''))));
    }

    public function redirect(Request $request)
    {
        $hint = strtolower(trim((string) $request->query('login_hint', '')));

        return $this->route($request, mb_substr($hint, 0, 254));
    }

    private function route(Request $request, string $email)
    {
        if ($email !== '') {
            $domain = str_contains($email, '@') ? substr(strrchr($email, '@'), 1) : $email;
            $staff = SamlConnectionDomain::query()
                ->join('saml_connections', 'saml_connections.id', '=', 'saml_connection_domains.saml_connection_id')
                ->where('saml_connection_domains.domain', $domain)
                ->where('saml_connections.enabled', true)
                ->select('saml_connections.uuid')
                ->first();

            if ($staff) {
                return redirect()->route('saml.login', ['connection' => $staff->uuid]);
            }
        }

        if (! MicrosoftSettings::configured()) {
            return redirect()->route('login')->withInput(['email' => $email])
                ->withErrors(['sso_email' => 'Microsoft sign-in is not set up for that email address. Use your password instead.']);
        }

        return redirect()->away($this->oidc->begin($request->session(), str_contains($email, '@') ? $email : null));
    }

    public function callback(Request $request)
    {
        $identity = null;

        try {
            if (! MicrosoftSettings::configured()) {
                throw new MicrosoftOidcException('Microsoft sign-in is not available.', 'disabled');
            }
            if ($request->query('error')) {
                $request->session()->forget('microsoft_oidc');
                throw new MicrosoftOidcException('Microsoft sign-in was cancelled or refused.', 'idp_error', [
                    'error' => mb_substr((string) $request->query('error'), 0, 64),
                ]);
            }

            $identity = $this->oidc->complete($request->session(), $request->query('code'), $request->query('state'));
            $user = $this->resolver->resolve($identity);
        } catch (MicrosoftOidcException $e) {
            return $this->fail($e, $identity);
        } catch (Throwable $e) {
            report($e);

            return $this->fail(new MicrosoftOidcException('Sign-in failed. Please try again or contact your administrator.', 'exception', ['error' => class_basename($e)]), $identity);
        }

        return $this->completeLogin($request, $user, $identity);
    }

    private function completeLogin(Request $request, User $user, MicrosoftIdentity $identity)
    {
        $first = $user->wasChanged('microsoft_oid');
        // Trust only when the setting is on AND this ID token states that MFA was performed.
        $trust = MicrosoftSettings::trustMfa() && $identity->mfaClaimed();

        if ($first) {
            $this->audit('auth.microsoft.linked', "Microsoft account linked for {$user->email}", $user, ['tid' => $identity->tenantId, 'oid' => $identity->objectId]);
        }

        if (! $trust && $user->hasConfiguredMfa()) {
            $request->session()->regenerate();
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => false, 'auth.via_pending' => [
                'via' => 'microsoft', 'user_id' => $user->getKey(),
                'keys' => ['auth.microsoft_oid' => $identity->objectId, 'auth.microsoft_tid' => $identity->tenantId],
            ]]);
            $this->audit('auth.microsoft.login.mfa_pending', "Microsoft identity accepted for {$user->email}; local MFA challenge required", $user, ['tid' => $identity->tenantId]);

            return redirect()->route('two-factor.login');
        }

        Auth::guard(config('fortify.guard', 'web'))->login($user);
        $request->session()->regenerate();
        $request->session()->put([
            'auth.via' => 'microsoft',
            'auth.microsoft_oid' => $identity->objectId,
            'auth.microsoft_tid' => $identity->tenantId,
            'auth.mfa_satisfied' => $trust,
        ]);

        $this->audit('auth.microsoft.login.success', "Microsoft sign-in for {$user->email}", $user, [
            'tid' => $identity->tenantId, 'oid' => $identity->objectId, 'mfa_trusted' => $trust,
        ]);

        return redirect()->intended(config('fortify.home', '/dashboard'));
    }

    private function fail(MicrosoftOidcException $e, ?MicrosoftIdentity $identity)
    {
        $this->audit('auth.microsoft.login.failed', "Microsoft sign-in refused ({$e->reason})", null, ['reason' => $e->reason] + $e->context, $identity?->upn);
        Log::info('Microsoft sign-in refused', ['reason' => $e->reason]);

        return redirect()->route('login')->withErrors(['email' => $e->getMessage()]);
    }

    private function audit(string $action, string $description, ?User $user, array $extra = [], ?string $email = null): void
    {
        AuditLogger::logSystem($action, $description, [
            'function' => 'sso',
            'service' => 'microsoft',
            'automated' => false,
        ] + $extra, ['user_email' => $user?->email ?? $email]);
    }
}
