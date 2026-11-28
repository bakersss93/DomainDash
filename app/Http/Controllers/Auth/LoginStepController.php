<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\LoginPolicy;
use App\Services\Microsoft\MicrosoftOidcClient;
use Illuminate\Http\Request;

/**
 * Email-first login. Step 1 asks for the email only; the server then shows either the password step or the
 * single-sign-on step (see LoginPolicy::stepFor). Nothing here calls an external service.
 */
class LoginStepController extends Controller
{
    private const MIN_MS = 120;

    public function __construct(private MicrosoftOidcClient $oidc)
    {
    }

    /** Step 1 submit: remember the email and go back to the login page, which renders the right step. */
    public function identify(Request $request)
    {
        $data = $request->validate(['email' => 'required|string|email|max:254']);
        $request->session()->put('login.email', strtolower(trim($data['email'])));
        $request->session()->forget('login.pw');

        return redirect()->route('login');
    }

    /** "Use a different email". */
    public function reset(Request $request)
    {
        $request->session()->forget(['login.email', 'login.pw']);

        return redirect()->route('login')->withInput([]);
    }

    /** Switch between the single-sign-on view and the password view of an SSO account (server enforces the policy). */
    public function mode(Request $request, string $mode)
    {
        abort_unless(in_array($mode, ['password', 'sso'], true), 404);
        $mode === 'password' ? $request->session()->put('login.pw', true) : $request->session()->forget('login.pw');

        return redirect()->route('login');
    }

    /**
     * Used by the login page while an email is typed. Unknown, inactive, non-SSO and "SSO unavailable" all answer
     * {"method":"password"}. Local reads only, and every answer takes at least MIN_MS so timing carries no signal.
     */
    public function method(Request $request)
    {
        $started = hrtime(true);
        $email = $request->input('email');
        $step = is_string($email) && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL)
            ? LoginPolicy::stepFor($email)
            : ['mode' => 'password', 'password' => true];

        $body = $step['mode'] === 'sso' ? ['method' => 'sso', 'password' => (bool) $step['password']] : ['method' => 'password'];

        $remaining = self::MIN_MS * 1000 - (int) ((hrtime(true) - $started) / 1000);
        if ($remaining > 0) {
            usleep($remaining);
        }

        return response()->json($body)->header('Cache-Control', 'no-store');
    }

    /** The "Log in with Microsoft" action. The decision is recomputed here; nothing from the page is trusted. */
    public function sso(Request $request)
    {
        $data = $request->validate(['email' => 'required|string|email|max:254']);
        $email = strtolower(trim($data['email']));

        $step = LoginPolicy::stepFor($email);
        if ($step['mode'] !== 'sso') {
            // Forged or stale request. Same generic answer as step 1 gives for everyone else.
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
            if ($user && ($reason = LoginPolicy::microsoftRefusal($user))) {
                AuditLogger::logSystem('sso.login.failed', "Single sign-on refused ({$reason}) for {$user->email}", [
                    'function' => 'sso', 'automated' => false, 'reason' => $reason, 'user_id' => $user->id,
                ], ['user_email' => $user->email]);
            }
            $request->session()->put('login.email', $email);

            return redirect()->route('login')->withErrors(['email' => 'Single sign-on is not available for this account.']);
        }

        $request->session()->put('login.email', $email);
        $request->session()->put('sso.remember', $request->boolean('remember'));

        if ($step['route']['type'] === 'saml') {
            return redirect()->route('saml.login', $step['route']['connection']->uuid);
        }

        return redirect()->away($this->oidc->begin($request->session(), $email));
    }
}
