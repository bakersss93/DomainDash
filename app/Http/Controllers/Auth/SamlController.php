<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SamlConnection;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Saml\SamlException;
use App\Services\Saml\SamlIdentity;
use App\Services\Saml\SamlService;
use App\Services\Saml\SamlUserProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class SamlController extends Controller
{
    public function __construct(private SamlService $saml, private SamlUserProvisioner $provisioner)
    {
    }

    /** SP metadata: public, contains no secrets. */
    public function metadata(SamlConnection $connection)
    {
        return response($this->saml->metadataXml($connection), 200, ['Content-Type' => 'application/samlmetadata+xml; charset=UTF-8']);
    }

    public function login(Request $request, SamlConnection $connection)
    {
        abort_unless($connection->enabled, 404);

        $return = (string) $request->query('return', '');
        $return = ($return !== '' && str_starts_with($return, '/') && ! str_starts_with($return, '//') && ! str_contains($return, '\\')) ? $return : null;

        return redirect()->away($this->saml->beginLogin($connection, $return));
    }

    public function acs(Request $request, SamlConnection $connection)
    {
        $email = null;

        try {
            abort_unless($connection->enabled, 404);

            [$identity, $returnTo] = $this->saml->consume($connection, (string) $request->input('SAMLResponse', ''));
            $email = $identity->email;
            $user = $this->provisioner->resolve($connection, $identity);
        } catch (SamlException $e) {
            return $this->fail($connection, $e, $email ?? ($identity->email ?? null));
        } catch (Throwable $e) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                throw $e;
            }
            report($e);

            return $this->fail($connection, new SamlException('Sign-in failed. Please try again or contact your administrator.', 'exception', ['error' => class_basename($e)]), $email);
        }

        return $this->completeLogin($request, $connection, $user, $identity, $returnTo);
    }

    private function completeLogin(Request $request, SamlConnection $c, User $user, SamlIdentity $identity, ?string $returnTo)
    {
        // Entra enforced MFA/conditional access, or DomainDash must still challenge locally.
        if (! $c->skip_local_mfa && $user->hasConfiguredMfa()) {
            $request->session()->regenerate();
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => false, 'auth.via_pending' => 'saml']);
            $this->audit('sso.login.mfa_pending', $c, $user->email, "SSO identity accepted for {$user->email}; local MFA challenge required", $user);

            return redirect()->route('two-factor.login');
        }

        Auth::guard(config('fortify.guard', 'web'))->login($user);
        $request->session()->regenerate();
        $request->session()->put([
            'auth.via' => 'saml',
            'auth.saml_connection_id' => $c->id,
            'auth.mfa_satisfied' => (bool) $c->skip_local_mfa,
        ]);

        $this->audit('sso.login.success', $c, $user->email, "SSO login for {$user->email} via {$c->name}", $user, [
            'roles' => $user->getRoleNames()->all(),
            'object_id' => $identity->objectId,
        ]);

        return redirect()->intended($returnTo ?: config('fortify.home', '/dashboard'));
    }

    private function fail(SamlConnection $c, SamlException $e, ?string $email)
    {
        $this->audit('sso.login.failed', $c, $email, "SSO login refused ({$e->reason}) via {$c->name}", null, ['reason' => $e->reason] + $e->context);
        Log::info('SAML login refused', ['connection' => $c->uuid, 'reason' => $e->reason]);

        return redirect()->route('login')->withErrors(['email' => $e->getMessage()]);
    }

    private function audit(string $action, SamlConnection $c, ?string $email, string $description, ?User $user, array $extra = []): void
    {
        AuditLogger::logSystem($action, $description, [
            'function' => 'sso',
            'service' => 'saml',
            'automated' => false,
            'connection' => $c->uuid,
            'connection_name' => $c->name,
            'connection_type' => $c->type,
        ] + $extra, ['user_email' => $email ?? $user?->email]);
    }
}
