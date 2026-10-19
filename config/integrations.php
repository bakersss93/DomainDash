<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shared HTTP behaviour for outbound Halo / IT Glue calls
    |--------------------------------------------------------------------------
    */
    'http' => [
        'connect_timeout' => (int) env('INTEGRATIONS_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('INTEGRATIONS_TIMEOUT', 30),
        // Total attempts for idempotent GETs (1 = no retry).
        'max_attempts' => (int) env('INTEGRATIONS_MAX_ATTEMPTS', 4),
        // First back-off step in milliseconds; doubles per attempt (+ jitter).
        'base_delay_ms' => (int) env('INTEGRATIONS_BASE_DELAY_MS', 500),
        // Never sleep inline longer than this. A longer Retry-After is thrown
        // to the caller (queued jobs release themselves for that long).
        'max_inline_sleep' => (int) env('INTEGRATIONS_MAX_INLINE_SLEEP', 30),
    ],

    'halo' => [
        'page_size' => 100,
        'max_pages' => 500,
        // Requests per minute the queue workers may send to Halo.
        'rate_limit_per_minute' => (int) env('HALO_RATE_LIMIT_PER_MINUTE', 120),
        'token_lock_seconds' => 10,
        'domain_asset_type_ttl' => 86400,
        'reference_data_ttl' => 3600,
        'ticket_list_ttl' => 60,
        'ticket_ttl' => 15,
    ],

    'itglue' => [
        'default_base_url' => 'https://api.itglue.com',
        // Regional API hosts. The x-api-key header is only ever sent to these.
        'allowed_hosts' => ['api.itglue.com', 'api.eu.itglue.com', 'api.au.itglue.com'],
        'page_size' => 1000,
        'max_pages' => 200,
        // IT Glue allows 3000 requests / 5 minutes (=600/min); stay below.
        'rate_limit_per_minute' => (int) env('ITGLUE_RATE_LIMIT_PER_MINUTE', 400),
    ],

    'sync' => [
        'queue' => env('INTEGRATIONS_QUEUE', 'default'),
        // Domains handled per queued push job.
        'push_chunk_size' => 25,
        // A queued/running SyncRun older than this no longer blocks a new one.
        'stale_run_minutes' => 120,
    ],
];
