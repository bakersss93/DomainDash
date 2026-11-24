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
            // Sandbox is not in public DNS (Sandbox guide 2.1); requests are pinned to this IP.
            'ip' => env('VOCUS_SANDBOX_IP', '203.23.116.113'),
        ],
        // Named plan speed tiers (Vocus bundle sheet "LineSpeed" values that are not "down/up"). These follow
        // NBN's published Home tiers and can be edited in Settings, Vocus, Plans (stored in the "vocus" setting).
        // Value: [download Mbps, upload Mbps], or null when the speed is not known (never hides the plan).
        'named_tiers' => [
            'Home Fast'           => [500, 50],
            'Home Super Fast'     => [750, 50],
            'Home Ultra Fast'     => [1000, 100],
            'Home Hyper Fast'     => [2000, 200],
            'Fixed Wireless Plus' => null,
        ],
        // Router CPEPlanIDs from the Vocus bundle sheet. Reference only: DomainDash does not ship modems yet
        // (that needs DIR::Create and a CPEDirectoryID), so these are not offered on any form.
        'router_cpe_plans' => [
            'VX220NBNConnect'     => 'TPLink Vx220 Fibre, HFC, Wireless',
            'VX220NBNConnectVDSL' => 'TPLink Vx220 VDSL (FTTB, FTTN)',
            'VX220FTTC'           => 'TPLink Vx220 FTTC',
            'VX220FTTCNCD'        => 'TPLink Vx220 FTTC + NCD',
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
        // Minimum seconds between manual TCAS polls of the same service (Examples 4.1.6:
        // overall order progress every 6-8 hours; excessive polling can trigger a ban).
        // DIR::Get BROADBAND takes "Main.Postcode" (Product Catalogue DIR 5.2); DIR::Create takes "Main.PostCode".
        'poll_cooldown_seconds' => (int) env('VOCUS_POLL_COOLDOWN', 300),
        // UNI-D port on a TRANSFER / OUTSTANDING order (FTTP and Fixed Wireless). Product Catalogue FIBRE
        // 4.1.1.3 / 4.1.2.3: param "DataPortNumber", mandatory (1..1), integer 1 to 4. (The consolidated table in
        // appendix 6.1 calls the same thing "DataPortID"; the per-scope tables and the Data Types agree on
        // DataPortNumber, so that is the default. Override only if Vocus tells you otherwise.)
        // A NEW order has no port param: "UNI-D Port: Auto selected. A free port is required" (4.1.1.1).
        'transfer_port_param' => env('VOCUS_TRANSFER_PORT_PARAM', 'DataPortNumber'),
        // Whether the NTDRecord Upload/DownloadSpeed values cap the plans offered on an FTTP / HFC / Wireless
        // line. UNVERIFIED: the Data Types list the fields but do not define them (unlike the copper pair
        // record, which says "theoretical speed in Mbps"). Set false to never filter plans by NTD speed.
        'ntd_speed_is_cap' => filter_var(env('VOCUS_NTD_SPEED_IS_CAP', true), FILTER_VALIDATE_BOOLEAN),
        // Minutes the qualify result is kept so the order / transfer form can list the lines and ports.
        'qualification_ttl_minutes' => (int) env('VOCUS_QUALIFICATION_TTL', 60),
        // Wall-clock budget of one queued sync job before it hands the remainder to a follow-up job.
        'sync_budget_seconds' => (int) env('VOCUS_SYNC_BUDGET', 150),
    ],

];
