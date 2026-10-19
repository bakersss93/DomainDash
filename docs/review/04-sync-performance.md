# 04 - HaloPSA / IT Glue Sync and Page-Load Performance Review

Scope: every code path where a web request, console command or job calls HaloPSA or IT Glue. Read-only review of /home/user/DomainDash. Line numbers are from the current working tree. "Fact" = read in code; "Inference" = estimate or unverified (no production data, no Halo/IT Glue tenant access, no timings measured).

## 1. Summary

The platform has no local mirror of Halo or IT Glue data. Almost every Halo-facing screen makes live HTTP calls inside the request, with 30s Guzzle timeouts and no caching beyond the OAuth token. The "sync" features (admin Settings buttons, the scheduler) are the same request-handling controller methods run in a loop, so they inherit the per-item call pattern. The queue exists in config (`database` driver) but is used only by `SyncSynergyDomainsJob`; no Halo or IT Glue work is queued.

Note on scope: the user said "calling to halo for asset information". In this code the pages a normal user hits that call Halo live are the Tickets screens (`TicketController`), and the admin pages that call Halo are the Clients and Settings sync screens. There is no user-facing page that lists Halo assets live; asset data is pulled only by admin sync actions. So "slow when calling Halo" is almost certainly (a) Tickets index/show for end users and (b) admin sync/link/import actions. Confirm which page the user means (see Open Questions).

## 2. Current call graph per slow path

### 2.1 Tickets list (end-user page, every load and every "load more")
- `routes` -> `TicketController::index` (app/Http/Controllers/TicketController.php:19)
- :45 `app(HaloPsaClient::class)` -> constructor runs `Setting::get('halo')` (DB query, uncached, app/Models/Setting.php:13) (HaloPsaClient.php:24)
- :46 `listTicketsForClient()` (HaloPsaClient.php:581) -> `GET api/tickets?client_id&count=25&page` (1 call), preceded by `getAccessToken()` (HaloPsaClient.php:53; token cached via `Cache`, so 0 or 1 extra POST per ~token lifetime).
- Filtering by service category/type is done in PHP after the fetch (TicketController.php:69-100). A "service" filter can therefore return fewer than 25 rows while `hasMore` is computed from the pre-filter count (:104) -- correctness quirk, not latency.
- The Blade page also does fetch() to the same route for pagination (resources/views/tickets/requests.blade.php:183).
- Cost: 1 Halo round trip per page view (plus a token POST on cache miss). Latency = Halo `tickets` endpoint latency (often 1-5s on busy tenants; inference). `hydrateTicketDetails()` (TicketController.php:726) would add 1 call per ticket (25 calls) but it is dead code, never called (fact: single grep hit). Do not wire it in.

### 2.2 Ticket detail
- `TicketController::show` (:147) -> `getTicket` (HaloPsaClient.php:613, 1 call) + `getTicketActions` (:631). `getTicketActions` tries up to 3 endpoints in order and only moves to the next on exception, so best case 1 call, worst case 3 (30s timeout each).
- `reply` (:288) and `close` (:355) each begin with another `getTicket` (:308, :375) to authorise, then do writes. `close` may call `listTicketStatuses` (resolveClosedStatus :860, HaloPsaClient.php:828) uncached each time when no mapping is configured.
- Cost: 2-4 calls per detail view. Ticket types/statuses are static reference data but never cached.

### 2.3 API tickets (Api\TicketController)
- `index` (app/Http/Controllers/Api/TicketController.php:15-60): with no `client_id` it loops over **every** client with `halopsa_reference` and calls `listTicketsForClient` sequentially (:44-51), swallowing errors. Cost = N_clients Halo calls per API request (N=100 -> 100 calls, 100s+ worst case). This is the worst request-time N+1 in the app. Also merges all results then filters locally (no pagination across clients).
- Other actions (:99, :116, :133, :148) are single/dual calls.

### 2.4 Admin > Clients
- `ClientsController::index` (:20) is DB only, but the view runs 2 count queries per client row (resources/views/admin/clients/index.blade.php:200-201): 2 x 15 = 30 extra queries per page. Local N+1, minor.
- `haloClients` (:210, loaded by the Import modal): its own token request every call (private `getHaloAccessToken` :798 does `Http::asForm()->post` with no Cache), then `GET /Client?count=100` once. **No pagination**: tenants with >100 clients are silently truncated (correctness bug). Returns `full_data` for every client to the browser (payload bloat).
- `importHaloClients` (:307): per selected client, `listDomainAssetsForClient` (:425). That fetches ALL assets for the client (`count=100`, no `assettype_id` filter, HaloPsaClient.php:191-231) and filters "Domain" type in PHP. Also does `new HaloPsaClient()` inside the loop (constructor = DB read). Cost: K selected clients x 1 big asset call. Truncates at 100 assets (a client with 300 servers/workstations may never return its Domain assets -- correctness bug).
- `linkDomainsFromHalo` (:707) and `syncDnsToHalo` (:600): 1 all-asset call each, then `syncDnsToHalo` loops domains: per domain a Synergy DNS fetch (`fetchDnsRecordsFromSynergy`, :874) + a Halo update (HaloPsaClient.php:412) = 2 external calls per domain, synchronous in the request.
- `itglueSearch` (:507): `listOrganisations()` (ItGlueClient.php:65) = 1 call, page size 100, **no pagination** (truncated beyond 100 orgs).
- `syncDomainsToItglue` (:527): per domain: Synergy DNS fetch + `ItGlueClient::syncDomain` (ItGlueClient.php:210) = find (GET) + create/update (POST/PATCH) = 3 external calls per domain, all in-request. A client with 40 domains = ~120 sequential calls; at ~1s each that is two minutes, past typical PHP/web-server timeouts.

### 2.5 Admin > Settings > Sync (SyncController, app/Http/Controllers/Admin/SyncController.php)
- `getHaloClients` (:23): `GET /client` unpaginated, no count param (Halo default page size applies), then in-PHP fuzzy matching (`findClientMatches` :1170) against all local clients.
- `syncHaloClients` (:79): per selected client `GET /client/{id}` (:122) - N calls, sequential - plus `syncClientDomainAssignments` (:1197) which calls `GET /asset` per client with hard-coded `assettype_id => 1` (assumption: Domain type = 1; elsewhere the type is discovered dynamically via `getDomainAssetTypeId` :560). Per client: ~2 calls plus a `Domain::where('name')->first()` per asset.
- `getHaloDomains` (:207): **per-domain** `checkDomainExistsInHalo` (:1232) -> `GET /Asset?count=1000&search=<domain>` for every domain in the DB (`Domain::with('client')->get()` :222). D domains = D calls, each returning up to 1000 asset rows. 300 domains = 300 sequential calls with large payloads. This is the heaviest per-item pattern in the app, and the response drives the Settings sync modal.
- `syncHaloDomains` (:249): per domain: `getDefaultSiteForClient` (:385 -> `getClient` full client GET, repeated for every domain even when many domains share the client), `findAssetByInventory` (:424, 1 GET), `createDomainAsset`/`updateAsset` (1 write), plus Synergy WHOIS/DNS fetch (`fetchWhoisFromSynergy`, `fetchDnsRecordsFromSynergy`). Roughly 4-6 external calls per domain, serial.
- `fetchAllItGlueOrganizations` (:651): properly paginates (100/page, 100ms sleep). Used by `getItGlueClients` (:596) and `suggestItGlueOrg` (:709), which re-downloads all orgs on every call, uncached.
- `syncItGlueConfigurations` (:977): per domain Synergy DNS fetch + `syncDomain` (3 IT Glue calls). Same shape as 2.4.
- `getItGlueConfigurations` (:862): DB only (uses `domain->itglue_id`), so cheap; relies on stale local flag.

### 2.6 Scheduler / console
- `ScheduleRegistrar` (app/Console/ScheduleRegistrar.php:12-67) reads `sync_schedule` from the settings table at schedule-build time and registers `domaindash:sync-task {task}` for `sync-halo-assets` and `sync-itglue` (hourly/daily/weekly, `withoutOverlapping(30)`), plus a separate `SyncSynergyDomainsJob` at 02:30 (the only queued job).
- `RunSyncTaskCommand` (app/Console/Commands/RunSyncTaskCommand.php:170-245) constructs `SyncController` and invokes its HTTP handler methods (`syncHaloClients`, `syncHaloDomains`, `syncItGlueConfigurations`) with fake `Request` objects. So the scheduled job runs inside the scheduler process (not a queue worker), serially, with all the per-domain call patterns above, with no chunking, no resume and no per-item failure isolation beyond what the controller does. `withoutOverlapping(30)` expires after 30 minutes, so a longer run can overlap itself (inference; depends on fleet size).
- `runHaloAssetSync` (:170) syncs all linked clients then all client-linked domains every run; there is no "changed since" filter, so every run costs the full O(clients + domains x ~5) calls.
- The scheduler itself needs `schedule:run` each minute (cron); not in repo (only composer `dev` script runs `queue:listen`). Deployment setup of cron/queue worker is unverified.

### 2.7 Other
- Dashboard (`Admin\DashboardController` ~:36-60) uses local `halo_asset_id` / `itglue_id` nullability for "not synced" lists: DB only, good pattern to keep. It queries `where halo_asset_id is null OR = ''` (indexed on halo_asset_id, but the `OR ''` defeats index use partially; trivial with limit 100).
- No middleware, Blade view or Livewire component calls Halo/IT Glue (Livewire not installed; grep clean). All browser-initiated calls are fetch() against controller JSON endpoints.

## 3. Request-count estimates (inference from the code; assume 100 clients, 400 domains, ~1s per external call, no network batching)

| Path | External calls | Rough latency |
|---|---|---|
| Tickets index | 1 (+1 token on miss) | 1-3s |
| Ticket show | 2 (up to 4) | 2-6s |
| Api tickets index, no client_id | 100 | ~100s (will 504) |
| Halo import modal load | 1 token POST + 1 list | 1-3s (but truncates at 100) |
| Import K=20 clients | 20 big asset calls | 20-60s |
| `getHaloDomains` (Settings modal) | 400 `Asset?count=1000&search` | 400-1200s |
| `syncHaloDomains` all domains | ~400 x (1 site GET + 1 find + 1 write + Synergy 1-2) ~ 2000 | 30+ min |
| `syncItGlueConfigurations` all domains | 400 x (Synergy 1 + ITG 3) ~ 1600 | 25+ min |
| Scheduled `sync-halo-assets` | 100 x 2 + domain pass above | ~30-45 min |
| Admin clients page | 0 external, +30 SQL | fine |

These are estimates; none were measured. Halo throttling (HTTP 429) behaviour is not handled anywhere: `request()` retries only a 401 once (HaloPsaClient.php:121-130).

## 4. Root causes, ranked

1. **Sync logic runs synchronously in HTTP request handlers (and the scheduler calls those handlers).** Long loops sit under web timeouts. (SyncController, ClientsController, RunSyncTaskCommand.)
2. **Per-item (N+1) external calls**: `getHaloDomains` per-domain asset search (:226); Api tickets loop over clients (:44); `getDefaultSiteForClient` per domain (:385); `syncDomain` 3 IT Glue calls/domain; per-client `GET /client/{id}` (:122).
3. **No local mirror of remote state.** Pages that need "does this exist in Halo / IT Glue?" ask the remote each time. Local `halo_asset_id`/`itglue_id` exist but are write-only flags, not a cache of remote fields (no remote updated-at, no asset payload).
4. **Full re-sync every run, no incremental filter.** Neither Halo `lastupdate`/date filters nor IT Glue `filter[updated_at]` are used anywhere.
5. **Over-fetching and wrong paging**: all asset types fetched to find "Domain" (HaloPsaClient.php:229); `count=100`/`1000` with no page loop (ClientsController :232, HaloPsaClient :191, ItGlueClient :65) => silent truncation, plus `full_data` payloads sent to the browser.
6. **No caching** beyond the OAuth token: Settings read from DB on every `Setting::get` (Setting.php:13) and `new HaloPsaClient()`; ClientsController re-requests an OAuth token each call (:798); ticket types/statuses, IT Glue org list, Halo client/site metadata uncached.
7. **Queue not used for integrations.** `QUEUE_CONNECTION=database` default and jobs migration exist (0001_01_01_000002_create_jobs_table.php), only `SyncSynergyDomainsJob` uses it. No evidence of a running worker (cannot verify).
8. **Schema gaps**: `clients.halopsa_reference` and `clients.itglue_org_id` have no index (create_clients_table). Migration `2025_12_11_000000_add_sync_fields_to_clients_table` adds a **second, unused** pair (`halo_psa_client_id`, `itglue_organization_id`); the model and all code use `halopsa_reference` / `itglue_org_id`. `database/add_sync_columns.sql` indexes the unused pair. Duplicate/dead columns invite divergence. `domains.halo_asset_id` and `domains.itglue_id` are indexed (2025_11_27_232935) but stored as unsigned bigint while code compares to `''` in dashboard queries. `domains.name` is unique (good, supports lookup by name).
9. **Admin Clients index per-row count queries** (view :200-201).
10. **Local-only inefficiencies**: no rate-limit handling, no 429/backoff, no retry/idempotency, fuzzy matching in PHP for every request.

## 5. Target design

Principle: pages read only local tables; Halo/IT Glue are touched only by queued jobs (and by user write actions, which are also queued where they loop). A request never loops over remote items.

### 5.1 Local tables / columns (migration sketch)

```php
// 2026_xx_xx_000001_add_sync_state_to_clients_and_domains.php
Schema::table('clients', function (Blueprint $t) {
    $t->index('halopsa_reference');
    $t->index('itglue_org_id');
    $t->timestamp('halo_synced_at')->nullable();
    $t->timestamp('itglue_synced_at')->nullable();
    $t->unsignedBigInteger('halo_default_site_id')->nullable(); // avoids getClient per domain
    // cleanup (separate, later): drop halo_psa_client_id, itglue_organization_id
});
Schema::table('domains', function (Blueprint $t) {
    $t->timestamp('halo_synced_at')->nullable();
    $t->timestamp('itglue_synced_at')->nullable();
    $t->string('halo_sync_hash', 64)->nullable();   // hash of pushed payload; skip unchanged
    $t->string('itglue_sync_hash', 64)->nullable();
    $t->text('halo_sync_error')->nullable();
    $t->text('itglue_sync_error')->nullable();
});

// halo_assets: local mirror of Halo assets (all or Domain type only at first)
Schema::create('halo_assets', function (Blueprint $t) {
    $t->unsignedBigInteger('halo_id')->primary();
    $t->unsignedBigInteger('halo_client_id')->index();
    $t->unsignedInteger('assettype_id')->index();
    $t->string('assettype_name')->nullable();
    $t->string('name')->nullable();
    $t->string('inventory_number')->nullable()->index();
    $t->json('payload')->nullable();
    $t->timestamp('remote_updated_at')->nullable()->index();
    $t->timestamp('synced_at')->nullable();
    $t->timestamps();
});

// itglue_organizations: mirror for picker/matching
Schema::create('itglue_organizations', function (Blueprint $t) {
    $t->unsignedBigInteger('itglue_id')->primary();
    $t->string('name')->index();
    $t->timestamp('remote_updated_at')->nullable()->index();
    $t->timestamp('synced_at')->nullable();
    $t->timestamps();
});

// sync_runs: powers "last synced at", progress and the Refresh button
Schema::create('sync_runs', function (Blueprint $t) {
    $t->id();
    $t->string('provider');                 // halo|itglue
    $t->string('scope');                    // clients|assets|domains|config
    $t->unsignedBigInteger('client_id')->nullable()->index(); // null = global
    $t->string('status');                   // queued|running|ok|partial|failed
    $t->unsignedInteger('total')->default(0);
    $t->unsignedInteger('done')->default(0);
    $t->unsignedInteger('failed')->default(0);
    $t->timestamp('cursor_at')->nullable(); // high-water mark used for incremental
    $t->foreignId('triggered_by')->nullable();
    $t->text('error')->nullable();
    $t->timestamp('started_at')->nullable();
    $t->timestamp('finished_at')->nullable();
    $t->timestamps();
    $t->index(['provider','scope','status']);
});
```

(Alternative to halo_assets: just keep `domains.halo_asset_id` and mirror only Domain-type assets. A table is recommended so non-domain asset views can be added later without more Halo calls; the table is the "what pages read" for any asset UI.)

### 5.2 Jobs (all `ShouldQueue`, queue `integrations`, names mirror existing `SyncSynergyDomainsJob` style)

| Job | Does | Notes |
|---|---|---|
| `SyncHaloClientsJob` | Pages `GET /client?pageinate=true&page_no&page_size=100` (loop until short page), upserts mapping candidates; no per-client GET | Incremental via `lastupdate`/date filter if the tenant supports it; fall back to full list (clients are few) |
| `SyncHaloAssetsJob(clientId\|null)` | Per client one paged call `GET /asset?client_id=&assettype_id=<Domain>` (type id resolved once and cached), upserts `halo_assets`, links `domains.halo_asset_id` by name | Dispatches one child job per client via `Bus::batch`; `halo_default_site_id` captured from the same pass |
| `PushDomainToHaloJob(domainId)` | Uses local `halo_assets` to decide create vs update (no `findAssetByInventory` call); skips when `halo_sync_hash` unchanged | Replaces `syncHaloDomains` loop (:294) |
| `SyncItGlueOrganizationsJob` | Pages `/organizations?page[size]=100&page[number]`, `sort=-updated-at` and `filter[updated-at]` after the last cursor; upserts `itglue_organizations` | Replaces uncached `fetchAllItGlueOrganizations` and the unpaged `listOrganisations` |
| `SyncItGlueConfigurationsJob(clientId\|null)` | One paged `GET /flexible_assets?filter[flexible-asset-type-id]=&filter[organization-id]=` per org to learn existing ids (1 call per ~100 assets instead of 1 per domain), then create/update only changed domains | Replaces `findDomainFlexibleAsset` per domain |
| `PushDomainToItGlueJob(domainId)` | create/patch using the id found above; stores `itglue_id`, `itglue_sync_hash`, `itglue_synced_at` | DNS fetch from Synergy moved here (also queued, also hash-skipped) |
| `SyncOrchestratorJob` | Creates a `sync_runs` row, fans out child jobs via `Bus::batch(...)->allowFailures()`, updates counts, writes AuditLogger entries as today | One entry point for scheduler and UI |

Job requirements:
- Rate-limit aware: `RateLimited`/`Redis::throttle`-style middleware (or `Cache::lock` + `releaseAfter`) per provider; honour `Retry-After` on HTTP 429; exponential `backoff()` [10, 60, 300]; `tries=5`; `WithoutOverlapping` keyed by provider+scope+client to prevent duplicate runs. (IT Glue documents a request-rate ceiling per API key; Halo applies rate limiting per tenant -- exact numbers not verified here; make the limit a Setting.)
- Idempotent (upsert by remote id), resumable (each child job is one client or one page), per-item failure recorded in `sync_error` not aborting the batch.
- `HaloPsaClient` gets a `paginate()` helper (page loop with `page_no`/`page_size`) and a shared `Http::retry` with 429 handling. Cache `Setting::get('halo')` per process (Cache::remember 60s, forgotten on save) so job workers do not hit the DB per call.

### 5.3 Incremental strategy
- Halo: per scope store `sync_runs.cursor_at` (start time of last successful run, minus a 5 minute overlap). Request assets/clients changed since then using Halo's date filters (`lastupdatefromdate` / `datesearch` family on the asset and client endpoints; exact parameter names to be confirmed against the tenant's Swagger at `<tenant>/api/swagger` because they vary by endpoint -- unverified here). If a filter is unavailable, fall back to full per-client asset list (cheap once it is one paged call per client rather than per domain).
- IT Glue: `sort=-updated-at` plus `filter[updated-at]=<cursor>,*` on organizations and flexible_assets; stop paging as soon as a record older than the cursor appears (sorted desc).
- Outbound (DomainDash -> Halo/IT Glue): push only when `sha1(payload)` differs from `*_sync_hash` or `domains.updated_at > *_synced_at`.
- Nightly full reconciliation (small, off-peak) to catch deletes and missed filters; incremental hourly.

### 5.4 Scheduler cadence (replaces `domaindash:sync-task` for these two tasks)

| Task | Cadence | Mode |
|---|---|---|
| Halo clients | every 6h | incremental |
| Halo assets (domain type) | hourly, `->withoutOverlapping()`, `->onOneServer()` | incremental |
| IT Glue organizations | every 6h | incremental |
| IT Glue configurations | hourly (push only changed) | hash diff |
| Full reconcile (both) | daily 03:15 | full |

Schedule via `Schedule::job(new SyncOrchestratorJob(...))->onQueue('integrations')` instead of invoking controller methods. Keep the existing admin-configurable frequency (settings `sync_schedule`) as the source of truth so the Settings UI continues to work. Run a dedicated worker: `php artisan queue:work --queue=integrations,default --tries=5 --max-time=3600` under Supervisor/systemd; add `queue:work` and `schedule:run` to deployment docs. Add `php artisan queue:failed` monitoring. Consider moving `QUEUE_CONNECTION` and `CACHE_STORE` to Redis if/when available (database queue is acceptable at this scale; note that the `cache` and `jobs` tables share the DB connection with the app).

### 5.5 "Last synced at" + "Refresh now" UX
- Client page and the admin Clients row show `Halo synced 12 min ago` / `IT Glue synced 2h ago` from `clients.halo_synced_at`, `clients.itglue_synced_at` (and `domains.*_synced_at`), with an error badge from `sync_error`.
- "Refresh now" button posts to `POST /admin/clients/{client}/sync/{provider}`; controller only creates a `sync_runs` row (status queued), dispatches `SyncHaloAssetsJob($client)` / `SyncItGlueConfigurationsJob($client)` and returns 202 + run id in under 100ms. UI polls `GET /admin/sync-runs/{id}` every 2-3s (or Livewire/Echo later) for progress `done/total`. Disable button while a run for the same provider+scope is queued/running; throttle `throttle:6,1`.
- Global "Sync all" on Settings dispatches `SyncOrchestratorJob`; the existing modals (`getHaloDomains`, `getItGlueConfigurations`) read from local tables only.

### 5.6 What pages read instead
| Page / endpoint | Reads |
|---|---|
| Settings sync modals, dashboard "not synced" lists, `exists_in_halo` | `domains.halo_asset_id` + `halo_assets` (local join) |
| Client import modal (`haloClients`) | local `halo_clients` list. If not adding a table, keep `Cache::remember('halo.clients', 15min)` and paginate fully in `SyncHaloClientsJob` |
| ITGlue org picker (`itglueSearch`, `suggestItGlueOrg`) | `itglue_organizations` (typeahead query, `LIKE name%`) |
| `linkDomainsFromHalo` | `halo_assets` filtered by client, no live call |
| Api tickets index (all clients) | Require `client_id`; for cross-client lists dispatch job + read a local `halo_tickets` cache (phase 3) or paginate server-side; never loop clients in-request |
| Tickets index/show | Keep live (data must be fresh) but add `Cache::remember` 30-60s per `(client, page, filter)` for the list, 5 min for ticket types/statuses (`listTicketTypes`, `listTicketStatuses`), a 15s cache for `getTicket` shared by show+reply+close, and make the list call asynchronous from the page shell (render page instantly, fetch rows via the existing JSON endpoint with a skeleton). Fix `hasMore` to use pre-filter counts and push filters into Halo query params (`tickettype_id`, category) instead of filtering in PHP. |

### 5.7 Caching TTLs (when a live call remains)
- OAuth token: existing, plus reuse it in `ClientsController` and `SyncController` (all three should use `HaloPsaClient`; delete the two private token implementations).
- Settings (`Setting::get`): 60s in-process/Cache, bust on save.
- Halo ticket types/statuses: 1h. IT Glue flexible-asset-type id and Halo domain asset-type id: 24h.
- Halo ticket list: 30-60s (bust on reply/close/store). Ticket detail: 15s.
- Org list/Halo client list fallback caches: 15 min.
- Everything in the sync path is job-driven and does not rely on TTLs.

### 5.8 Webhooks (later enhancement)
Halo supports outbound webhooks (configured under Configuration > Integrations > Webhooks, triggered by events such as asset/ticket changes). A `POST /webhooks/halo` endpoint (signature/secret verified, returns 202, dispatches `SyncHaloAssetsJob(clientId)` or marks the local `halo_assets` row stale) would remove most polling and make tickets near-real-time. Exact event list and payload signing should be verified in the tenant before design. IT Glue does not offer comparable webhooks to my knowledge (unverified); keep `updated-at` polling there.

## 6. Migration / job list

Migrations (in order, each independently deployable):
1. `add_indexes_to_clients_for_sync` (index `halopsa_reference`, `itglue_org_id`)
2. `add_sync_state_to_clients_and_domains`
3. `create_halo_assets_table`
4. `create_itglue_organizations_table`
5. `create_sync_runs_table`
6. (later, after verifying zero usage) `drop_unused_halo_psa_client_id_and_itglue_organization_id_from_clients`; remove `database/add_sync_columns.sql`

New classes: `SyncOrchestratorJob`, `SyncHaloClientsJob`, `SyncHaloAssetsJob`, `PushDomainToHaloJob`, `SyncItGlueOrganizationsJob`, `SyncItGlueConfigurationsJob`, `PushDomainToItGlueJob`, `App\Support\SyncRun` (model), `ProviderRateLimiter` job middleware, `HaloPsaClient::paginate()` and 429 handling, `ItGlueClient::paginate()` and `listFlexibleAssets()` / `listOrganisationsPaged()`.

Refactors: move logic out of `SyncController`/`ClientsController` private helpers into the jobs/services; controllers become thin dispatchers; `RunSyncTaskCommand` becomes a thin wrapper dispatching the orchestrator (kept for backward compatibility with the admin schedule settings).

## 7. Rollout steps

1. **Quick wins (no schema change, ship first, 1 day):** fix `haloClients` and `listOrganisations`/`listAssetsForClient` to paginate; add `assettype_id` filter in `listDomainAssetsForClient`; replace per-domain `checkDomainExistsInHalo` with one bulk domain-asset fetch per run or the local `halo_asset_id` flag; stop `Api\TicketController` all-client loop (require `client_id` or cap/queue); cache ticket types/statuses/settings/org list; use shared `HaloPsaClient` token everywhere; fix Clients index N+1 (`withCount`). Run `php artisan queue:work` under Supervisor and verify the cron `schedule:run`.
2. **Schema:** ship migrations 1-5 (additive, zero downtime).
3. **Jobs behind a feature flag** (`Setting: sync.queued=true`): implement jobs, rate limiter, `sync_runs`. Run orchestrator in dry-run mode against staging/Halo test tenant, compare results with the existing synchronous path.
4. **Backfill:** run a one-off full `SyncOrchestratorJob` to populate `halo_assets`, `itglue_organizations`, and `*_synced_at`.
5. **Switch readers** (Settings modals, dashboard, linking, org picker) to local tables; ship "last synced" and "Refresh now" UX.
6. **Switch scheduler** to dispatch the orchestrator on the cadence in 5.4; keep the old command path for one release as fallback; remove the controller-driven loops afterwards.
7. **Monitoring:** log per-run duration, call counts, 429s into `sync_runs` and `AuditLogger`; alert on `failed_jobs` and runs older than 2x cadence.
8. **Later:** Halo webhooks; `halo_tickets` cache; Redis queue/cache; drop obsolete columns.

## 8. Open questions / uncertainty
- No profiling or tenant access: all timings are estimates; the first step should be adding timing logs around `HaloPsaClient::request` and `ItGlueClient` HTTP calls to confirm which paths dominate (cheap: Log `method, uri, ms`).
- Which exact page the user finds slow ("asset information") is not identifiable from code alone; the heaviest asset-related paths are `getHaloDomains` (per-domain search), `importHaloClients`, `syncHaloDomains`, and the scheduler's `sync-halo-assets`.
- Halo `lastupdate`/date-filter parameter names and Halo/IT Glue rate limits must be confirmed against the live tenant Swagger/docs.
- Whether a queue worker and `schedule:run` cron are actually running in production could not be verified from the repo.
- `syncClientDomainAssignments` hard-codes `assettype_id => 1`, which may be wrong for the tenant (other code resolves it dynamically).

## 9. Key file references
- /home/user/DomainDash/app/Services/Halo/HaloPsaClient.php (request :110, listClients :144, listAssetsForClient :191, listDomainAssetsForClient :229, findAssetByInventory :329, getDefaultSiteForClient :365, listTicketsForClient :581, getTicketActions :631)
- /home/user/DomainDash/app/Services/ItGlue/ItGlueClient.php (listOrganisations :65, findDomainFlexibleAsset :107, syncDomain :210)
- /home/user/DomainDash/app/Http/Controllers/Admin/SyncController.php (:23, :79, :207, :249, :596, :651, :977, :1197, :1232)
- /home/user/DomainDash/app/Http/Controllers/Admin/ClientsController.php (:210, :307, :507, :527, :600, :707, :798)
- /home/user/DomainDash/app/Http/Controllers/TicketController.php (:19, :147, :288, :355, :726 dead code)
- /home/user/DomainDash/app/Http/Controllers/Api/TicketController.php (:15-60)
- /home/user/DomainDash/app/Console/ScheduleRegistrar.php, app/Console/Commands/RunSyncTaskCommand.php (:170-245), app/Jobs/SyncSynergyDomainsJob.php
- /home/user/DomainDash/config/queue.php (:16 default `database`), database/migrations/0001_01_01_000002_create_jobs_table.php, 2025_11_27_232935_add_halo_itglue_id_to_domains_table.php, 2025_12_11_000000_add_sync_fields_to_clients_table.php
