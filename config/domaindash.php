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

    /*
    | Platform HTTPS (see README "Platform HTTPS").
    |
    | The web process only writes requests to the database and reads status back.
    | Everything below `paths`/`commands` is consumed by the ROOT helper
    | (`php artisan domaindash:certctl`, run by systemd). Paths are deliberately NOT
    | env-overridable: the root helper must never be redirected by a writable .env.
    */
    'https' => [
        // Public IP of this server, shown/compared in pre-flight hints (optional, web only).
        'public_ip' => env('PLATFORM_PUBLIC_IP'),
        // Seconds without a helper heartbeat before the UI warns.
        'heartbeat_stale_seconds' => 300,
        // DNS-01 propagation wait (clamped 30..900s / 2..60s).
        'dns_timeout' => max(30, min(900, (int) env('HTTPS_DNS_PROPAGATION_TIMEOUT', 180))),
        'dns_poll_interval' => max(2, min(60, (int) env('HTTPS_DNS_POLL_INTERVAL', 10))),
        // Extra wait after the TXT is visible so every Let's Encrypt vantage point sees it.
        'dns_settle_seconds' => max(0, min(120, (int) env('HTTPS_DNS_SETTLE_SECONDS', 15))),
        'dns_ttl' => 300,
        'upload_max_kb' => 64,

        'paths' => [
            'ssl_snippet' => '/etc/nginx/snippets/domaindash-ssl.conf',
            'ssl_dir' => '/etc/ssl/domaindash',
            'letsencrypt_live' => '/etc/letsencrypt/live',
            'deploy_hook' => '/usr/local/lib/domaindash/certbot-deploy-hook',
            'auth_hook' => '/usr/local/lib/domaindash/acme-dns-auth',
            'cleanup_hook' => '/usr/local/lib/domaindash/acme-dns-cleanup',
        ],
        'commands' => [
            'certbot' => '/usr/bin/certbot',
            'nginx' => '/usr/sbin/nginx',
            'systemctl' => '/usr/bin/systemctl',
        ],
        'certbot_timeout' => 900,
    ],
];
