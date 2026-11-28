<?php

/*
|--------------------------------------------------------------------------
| Monitoring (Admin > Monitoring)
|--------------------------------------------------------------------------
| Defaults only. Administrators change the live values under
| Admin > Monitoring > Logging settings (stored in the `audit` settings group).
| See docs/review/19-monitoring-logs.md.
*/
return [
    // Debug firehose: also record GET page views, routine console/queue/scheduler
    // start/finish events and the queue heartbeat. Off in production use.
    'verbose' => (bool) env('MONITORING_VERBOSE', false),

    // Outbound integration requests (Halo, IT Glue, Synergy, Vocus, ip2whois): errors | all
    'outbound_level' => env('MONITORING_OUTBOUND_LEVEL', 'errors'),

    // Inbound API requests (API keys): errors | all
    'inbound_level' => env('MONITORING_INBOUND_LEVEL', 'all'),

    // Mask customer e-mail / phone / date-of-birth values inside stored request bodies.
    'mask_pii' => (bool) env('MONITORING_MASK_PII', true),

    // Stored request / response bodies are cut to this many bytes.
    'body_limit' => (int) env('MONITORING_BODY_LIMIT', 65536),

    // Days to keep each category (activity falls back to the legacy audit.retention_days setting).
    'retention' => [
        'activity' => (int) env('MONITORING_RETENTION_ACTIVITY', 90),
        'outbound' => (int) env('MONITORING_RETENTION_OUTBOUND', 30),
        'inbound' => (int) env('MONITORING_RETENTION_INBOUND', 30),
        'jobs' => (int) env('MONITORING_RETENTION_JOBS', 90),
    ],

    // Security events (sign-ins, MFA, impersonation, secret reveals, API keys, settings, SSO, backups) are always
    // kept at least this many days, whatever the activity retention is set to. Cannot be configured lower.
    'security_retention_floor_days' => 365,

    // Also send every audit event to the syslog (channel `audit_syslog`) so a copy exists outside the database.
    'syslog' => (bool) env('AUDIT_SYSLOG', false),

    // A console command / scheduled task slower than this (seconds) is recorded as a summary even when it succeeded.
    'slow_seconds' => (int) env('MONITORING_SLOW_SECONDS', 120),

    // Identical failures (same command / job) are recorded at most once per this many minutes.
    'failure_throttle_minutes' => (int) env('MONITORING_FAILURE_THROTTLE', 15),

    // Audit actions that are noise unless `verbose` is on. Also hidden from the Activity tab by default
    // (older rows written before the rework are still in the table).
    'noise_actions' => [
        'http.get', 'http.head', 'http.options',
        'console.command_started', 'console.command_finished',
        'queue.job_started', 'queue.job_finished',
        'scheduler.task_started', 'scheduler.task_finished', 'scheduler.task_skipped',
    ],

    // Console commands that never produce an event, even on failure (long-running daemons).
    'silent_commands' => ['schedule:run', 'schedule:work', 'queue:work', 'queue:listen', 'queue:restart', 'domaindash:certctl'],

    // Jobs that never produce an event unless verbose (liveness probes).
    'silent_jobs' => ['App\\Jobs\\QueueHeartbeatJob'],
];
