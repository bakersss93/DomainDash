# 19. Monitoring: meaningful logs (Halo-style Backend Service Monitoring)

## Problem
`/admin/audit` ("Event Timeline") was dominated by noise: every `GET` page view ("Manual HTTP action GET /admin/clients"),
`console.command_started/finished` (`domaindash:certctl` every minute), `queue.job_started/finished` (heartbeat) and
`scheduler.task_finished`. Real events (who changed which client, what Halo rejected) were buried, and there was no view of
integration traffic at all. Target: HaloPSA "Backend Service Monitoring": separate tabs, compact searchable tables, row click opens
a Request / Response detail modal.

## Log categories

| Category | Tab | Source | Default retention |
|---|---|---|---|
| Activity | Activity | `audit_logs` (user actions that change data, security events, settings, orders, sync outcomes) | 90 d |
| Integration outbound | Integration Outbound | `integration_requests` direction=outbound: Halo, IT Glue, IP2WHOIS (Laravel Http), Synergy, Vocus (SOAP) | 30 d |
| Integration inbound | Integration Inbound | `integration_requests` direction=inbound: API-key requests (`ApiKeyAuth`). No webhook endpoints exist in the app today; any future one should call `IntegrationRequestLogger::logInbound`. | 30 d |
| Integration errors | Integration Errors | derived: failed outbound requests + `domains.halo_sync_error`, `domains.itglue_sync_error`, `internet_services.halo_sync_error` | follows source |
| Jobs and schedules | Jobs & Schedules | `sync_runs` (scope, status, counts, started/finished, error) | 90 d |

## What is recorded, what is dropped

Recorded in Activity
- Mutating web/API requests (POST/PUT/PATCH/DELETE) as one row with a sentence ("Updated client Example Pty Ltd", "Ordered NBN service",
  "Changed HaloPSA settings") from `App\Support\Monitoring\AuditDescriber` (rules keyed by `METHOD route-uri`, works for unnamed routes;
  unmapped routes fall back to "Submitted <path>").
- Old -> new field diff: `ChangeTracker` collects Eloquent created/updated/deleted events during the request. Settings changes record the key only, never values.
- When a controller already wrote its own audit event (most do: domain/ssl/internet/vocus/sync/settings...), no second generic row is added.
- Logins, failed logins, logout, lockout, password reset, MFA enrol/confirm/disable/failed/recovery-code used.
- Sync started/completed with counts (existing explicit events), imports, orders, impersonation.
- System failures only: console command failed (domaindash:*), command/scheduled task slower than `monitoring.slow_seconds` (120 s), scheduler task failed, queue job failed. Failures are throttled to one row per command/job per 15 minutes.
- Monitoring's own actions: settings changed, entry exported, entry deleted, retention pruned.

Dropped (unless Verbose is on)
- `GET`/HEAD/OPTIONS page views; read-only POSTs (search, lookup, validate, preview, availability, qualify); 401/404/405/419/422/429 responses.
- `console.command_started/finished`, `queue.job_started/finished`, `scheduler.task_started/finished/skipped`, `QueueHeartbeatJob` (including its failures).
- Sync progress is the job of `sync_runs`, not per-job events.

Verbose / debug: `monitoring.verbose` (env `MONITORING_VERBOSE`, default false) or the "Verbose debug logging" switch in Monitoring > Logging settings.
Rows written before this rework stay in `audit_logs`; the Activity tab hides their noise actions (checkbox "Include debug events" shows them) and retention removes them.

## Outbound integration request log (`integration_requests`)

Columns: `direction`, `provider` (halo|itglue|synergy|vocus|ip2whois|api), `method` (HTTP verb or SOAP), `operation`, `url` (secrets stripped),
`request_headers` (masked), `request_body` (pretty JSON/XML, redacted, truncated), `response_status`, `response_headers` (allow-list),
`response_body`, `duration_ms`, `entity_type` / `reference` (domain name, client, service id), `sync_run_id`, `api_key_id` / `actor` / `ip` (inbound),
`is_error` / `error_message`, `created_at`, truncation flags. Indexes: `(direction, created_at)`, `(direction, provider, created_at)`,
`(is_error, created_at)`, `reference`, `sync_run_id`. Migration also backfills the last 30 days of `api_access_logs` as inbound rows (no bodies existed then) and adds `audit_logs (action, created_at)`.

Capture points
- Laravel Http: a Guzzle middleware (`IntegrationRequestLogger::middleware`) added by `IntegrationHttp::pending(..., $provider)` (Halo, IT Glue) and used directly by `Ip2whoisClient`. Every attempt (including retries) is one row; connection failures are rows with no response.
- SOAP: `SynergyWholesaleClient::invoke` and `VocusWsmClient::call` call `logSoap` (operation, endpoint, sanitised request XML, response or fault). Synergy requests are rendered from the redacted payload array, so `apiKey` never reaches the XML. Vocus's login (curl) is not logged.
- Inbound: `ApiKeyAuth::log` -> `logInbound` (accepted, rejected, throttled).
- Context: `withContext(['entity_type'=>..., 'reference'=>...])` wraps the Halo/IT Glue per-record pushers; `SyncRun::markRunning` links later calls to the run until `finish`/`fail`. Otherwise the reference is derived from the URL (`domain=` query or the API path).

Level (Monitoring > Logging settings): outbound `errors` (default, status >= 400, connection failure, SOAP fault/`ERR*` status) or `all`; inbound default `all` (traffic is low and already counted in `api_access_logs`), can be `errors`.

## Security and PII rules
- Never stored: Authorization, x-api-key full value, cookies/Set-Cookie, AccessKey/apiKey/alias keys, passwords (including PPPoE), EPP/auth codes, client secrets, tokens, signatures. Enforced by key (`Redactor::isSecretKey`) in JSON, form, XML elements, XML param ids and a text fallback; header values show an 8-char prefix ("ITG.7474…") or nothing when short; URL userinfo and secret query values are replaced.
- Bodies are cut to `monitoring.body_limit` (64 KB) with a marker; binary content is replaced by a note. Inbound endpoints whose path contains `auth-code|password|credential|login|token` store no bodies.
- Customer e-mail / phone / date-of-birth style fields are partially masked in stored bodies (`mask_pii`, default on). Activity old -> new diffs keep values (needed to audit a change) but redact secret keys.
- The server redacts again when serving a detail or export (defence in depth).
- Admin only: all routes sit behind `role:Administrator` and the MFA policy; the controller re-checks the role. Export and delete write audit rows (`monitoring.entry_exported`, `monitoring.entry_deleted`).
- Activity rows cannot be deleted from the UI (append-only; retention only). Failed/derived sync-error rows are read-only; integration requests and finished sync runs can be deleted.
- Logging never breaks the call: every write is wrapped (`safely`), event listeners are guarded, the audit middleware catches everything.
- All dynamic text is rendered with `textContent` / DOM construction (no `innerHTML`); colours use existing tokens.

## Retention
Settings group `audit`: `retention_days` (activity, existing key), `retention_days_outbound`, `retention_days_inbound`, `retention_days_jobs`, `outbound_level`, `inbound_level`, `verbose`, `mask_pii`.
`php artisan domaindash:audit-prune` (scheduled 03:30) runs `MonitoringPruner`: per-category chunked deletes (5000 rows), also prunes `api_access_logs` by the inbound days, and records a `monitoring.pruned` summary. "Apply retention now" in the settings form runs the same pruner.

## UI (`/admin/audit`, "Monitoring")
Tabs: Activity | Integration Outbound | Integration Inbound | Integration Errors | Jobs & Schedules (ARIA tablist, arrow keys, `?tab=`).
Each tab: search (debounced, server side), filters (provider/module, status, from/to date; Activity also "Include debug events"), compact `x-ui.table`, pager "1-50 of N" with previous/next and refresh, row click or Enter opens the detail modal.

| Tab | Columns |
|---|---|
| Activity | When, User, Module, Description, Reference, Status |
| Integration Outbound | ID, When, Provider, Description, Reference, Status (HTTP badge), Time |
| Integration Inbound | ID, When, API key, Request, Status, IP |
| Integration Errors | When, Module, Entity, Reference, Error |
| Jobs & Schedules | ID, Started, Provider, Scope, Client, Items, Duration, Status |

Detail modal: title with success/failed icon, tabs. Requests: **Request** (Timestamp, Method/Operation, URL, Headers, Payload) and **Response** (status badge, Duration, Headers, Body, Error). Activity: **Details**, **Changes** (old -> new table, count badge), **Request** (payload). Jobs: **Run**, **Requests** (calls recorded for the run). Footer: Export to JSON (download), Delete (two-step confirm, only where allowed).
Endpoints (all `Administrator`): `GET /admin/audit/data/{tab}`, `GET /admin/audit/entry/{tab}/{id}`, `.../export`, `DELETE /admin/audit/entry/{tab}/{id}`, `POST /admin/audit/retention` (settings). List queries use the new indexes and `forPage`; text search is a bounded `LIKE` on retention-limited tables.

## Files
`config/monitoring.php`; `app/Services/Monitoring/{MonitoringSettings,IntegrationRequestLogger,MonitoringFeed,MonitoringPruner}.php`; `app/Support/Monitoring/{AuditDescriber,ChangeTracker,SystemEventAuditor}.php`;
`app/Models/IntegrationRequest.php`; migration `2026_11_30_000001_create_integration_requests_table.php`; `AuditRequestActions`, `AuditLogger`, `ApiKeyAuth`, `AppServiceProvider`, `Redactor`, `IntegrationHttp`, clients and pushers (context); `AuditLogController`; `resources/views/admin/audit/index.blade.php`.
Tests: `tests/Feature/Monitoring/*`, `tests/Unit/Monitoring/RedactorMonitoringTest.php`.
