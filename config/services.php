<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Synergy Wholesale SOAP API. Credentials live in the "synergy" setting;
    | everything below is deployment configuration.
    */
    'synergy' => [
        // Bundled copy of the live WSDL (refreshed 2026-10-19, API v3.18).
        'wsdl' => env('SYNERGY_WSDL', base_path('resources/wsdl/synergy.wsdl')),
        // Overrides the <soap:address> in the WSDL (null = use the WSDL value).
        'endpoint' => env('SYNERGY_ENDPOINT'),
        // Sandbox/test override: when SYNERGY_SANDBOX=true and a sandbox
        // endpoint is set, it takes precedence over SYNERGY_ENDPOINT.
        'sandbox' => (bool) env('SYNERGY_SANDBOX', false),
        'sandbox_endpoint' => env('SYNERGY_SANDBOX_ENDPOINT'),
        'connection_timeout' => (int) env('SYNERGY_CONNECTION_TIMEOUT', 10),
        'timeout' => (int) env('SYNERGY_TIMEOUT', 30),
        // none|disk|memory|both
        'wsdl_cache' => env('SYNERGY_WSDL_CACHE', 'disk'),
    ],

    /*
    | Vocus Wholesale Service Management (WSM). Credentials/cert live in the
    | "vocus" setting.
    */
    'vocus' => [
        // production|sandbox
        'environment' => env('VOCUS_ENVIRONMENT', 'production'),
        'production' => [
            'endpoint' => env('VOCUS_PRODUCTION_ENDPOINT', 'https://wsm.webservice.m2.com.au/WholesaleServiceManagement'),
            'login_url' => env('VOCUS_PRODUCTION_LOGIN_URL', 'https://wsm.webservice.m2.com.au:9443/login/'),
        ],
        // The sandbox is reached via a hosts-file override of the production
        // hostname and presents a self-signed certificate: supply it with
        // VOCUS_CA_BUNDLE. TLS verification is never disabled.
        'sandbox' => [
            'endpoint' => env('VOCUS_SANDBOX_ENDPOINT', 'https://wsm.webservice.m2.com.au/WholesaleServiceManagement'),
            'login_url' => env('VOCUS_SANDBOX_LOGIN_URL', 'https://wsm.webservice.m2.com.au:9443/login/'),
        ],
        // Optional PEM CA bundle / pinned certificate used for login + SOAP.
        'ca_bundle' => env('VOCUS_CA_BUNDLE'),
        'connection_timeout' => (int) env('VOCUS_CONNECTION_TIMEOUT', 10),
        'timeout' => (int) env('VOCUS_TIMEOUT', 30),
        // Vocus allows 5 requests per 10 seconds; stay slightly under.
        'rate_limit' => [
            'max_requests' => (int) env('VOCUS_RATE_LIMIT_MAX', 4),
            'per_seconds' => (int) env('VOCUS_RATE_LIMIT_SECONDS', 10),
            'max_wait_seconds' => (int) env('VOCUS_RATE_LIMIT_MAX_WAIT', 15),
        ],
        'session_ttl' => (int) env('VOCUS_SESSION_TTL', 540),
    ],

];
