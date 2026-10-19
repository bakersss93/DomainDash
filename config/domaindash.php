<?php

return [
    /*
    | Public self-registration. DomainDash is an invite-only MSP portal; users are
    | created by administrators. Leave OFF unless you explicitly want sign-ups.
    */
    'allow_registration' => (bool) env('ALLOW_REGISTRATION', false),

    'mfa' => [
        /*
        | Enforce MFA for the roles below: users in these roles who have not
        | confirmed 2FA can only reach the dashboard (setup prompt) and the
        | /me/mfa/* + Fortify two-factor endpoints until they enrol.
        |
        | BREAK-GLASS: set MFA_ENFORCE=false in .env (and `php artisan config:clear`)
        | to lift enforcement temporarily, or reset one user with
        | `php artisan domaindash:mfa-reset user@example.com`.
        */
        'enforce' => (bool) env('MFA_ENFORCE', true),
        'required_roles' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MFA_REQUIRED_ROLES', 'Administrator'))
        ))),
    ],

    'security_headers' => [
        'enabled' => (bool) env('SECURITY_HEADERS', true),
        // Content-Security-Policy is report-only by default so it cannot break
        // inline scripts/styles; set CSP_ENFORCE=true once reports are clean.
        'csp_enforce' => (bool) env('CSP_ENFORCE', false),
        'csp_report_uri' => env('CSP_REPORT_URI'),
    ],
];
