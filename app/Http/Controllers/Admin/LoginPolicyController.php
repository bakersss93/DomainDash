<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\Auth\LoginPolicy;
use App\Services\Microsoft\MicrosoftSettings;
use App\Support\Redactor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Administrator-only (route group `role:Administrator`): client Microsoft sign-in settings and the login-method policy. */
class LoginPolicyController extends Controller
{
    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** Client Microsoft sign-in: enable, application (client) ID, client secret, allowed tenants, MFA trust. */
    public function updateMicrosoft(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'nullable|boolean',
            'client_id' => ['nullable', 'string', 'regex:'.self::GUID],
            'client_secret' => 'nullable|string|max:512',
            'allowed_tenants' => 'nullable|string|max:5000',
            'trust_mfa_clients' => 'nullable|boolean',
        ]);

        $current = MicrosoftSettings::all();
        $new = [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'client_id' => strtolower(trim((string) ($data['client_id'] ?? ''))),
            'client_secret' => $data['client_secret'] ?? '',
            'allowed_tenants' => [],
            'trust_mfa_clients' => (bool) ($data['trust_mfa_clients'] ?? false),
        ];
        // Blank or the "********" placeholder keeps the stored secret.
        $new = Redactor::preserveSecrets($new, $current);
        $new['client_secret'] = (string) ($new['client_secret'] ?? '');

        $tenants = [];
        foreach (preg_split('/[\s,;]+/', (string) ($data['allowed_tenants'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $t) {
            if (! preg_match(self::GUID, $t)) {
                throw ValidationException::withMessages(['allowed_tenants' => "\"{$t}\" is not a tenant ID (a GUID)."]);
            }
            $tenants[] = strtolower($t);
        }
        $new['allowed_tenants'] = array_values(array_unique($tenants));

        if ($new['enabled'] && ($new['client_id'] === '' || $new['client_secret'] === '')) {
            throw ValidationException::withMessages(['enabled' => 'Enter the Application (client) ID and a client secret before enabling Microsoft sign-in.']);
        }
        if (! $new['enabled'] && LoginPolicy::clientsDependOnMicrosoft()) {
            throw ValidationException::withMessages(['enabled' => 'Password sign-in is closed for clients (globally or for a specific client), so Microsoft sign-in cannot be turned off. Reopen password sign-in first.']);
        }

        Setting::put(MicrosoftSettings::KEY, $new);

        AuditLogger::logSystem('settings.microsoft_signin.updated', 'Updated Microsoft sign-in (clients) settings', ['function' => 'sso'], [
            'old_values' => $this->auditView($current),
            'new_values' => $this->auditView($new),
        ]);

        return redirect()->route('admin.sso.index')->with('status', 'Microsoft sign-in settings saved.');
    }

    /** Login-method policy per audience. Refused unless it cannot lock everyone out. */
    public function updatePolicy(Request $request)
    {
        $data = $request->validate([
            'staff_login' => ['required', Rule::in([LoginPolicy::BOTH, LoginPolicy::MICROSOFT_ONLY])],
            'client_login' => ['required', Rule::in([LoginPolicy::BOTH, LoginPolicy::MICROSOFT_ONLY])],
        ]);

        $before = LoginPolicy::settings();

        if ($data['staff_login'] === LoginPolicy::MICROSOFT_ONLY) {
            $problems = LoginPolicy::staffMicrosoftOnlyProblems();
            if ($problems) {
                throw ValidationException::withMessages(['staff_login' => implode(' ', $problems)]);
            }
        }
        if ($data['client_login'] === LoginPolicy::MICROSOFT_ONLY) {
            $problems = LoginPolicy::clientMicrosoftOnlyProblems();
            if ($problems) {
                throw ValidationException::withMessages(['client_login' => implode(' ', $problems)]);
            }
        }

        Setting::put('sso', array_merge($before, [
            'staff_login' => $data['staff_login'],
            'client_login' => $data['client_login'],
        ]));

        AuditLogger::logSystem('settings.login_policy.updated', 'Updated login-method policy', ['function' => 'sso'], [
            'old_values' => ['staff_login' => $before['staff_login'], 'client_login' => $before['client_login']],
            'new_values' => ['staff_login' => $data['staff_login'], 'client_login' => $data['client_login']],
        ]);

        return redirect()->route('admin.sso.index')->with('status', 'Login-method policy saved.');
    }

    private function auditView(array $s): array
    {
        return [
            'enabled' => $s['enabled'], 'client_id' => $s['client_id'], 'app_key_stored' => ($s['client_secret'] ?? '') !== '',
            'allowed_tenants' => $s['allowed_tenants'], 'trust_mfa_clients' => $s['trust_mfa_clients'],
        ];
    }
}
