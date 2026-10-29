# 11 - HaloPSA "sync domains to Halo" diagnosis

Branch claude/eloquent-hypatia-5lio42, read-only review. Labels: FACT = read in code or reproduced with a scratch SQLite DB (`QUEUE_CONNECTION=database`, `php artisan queue:work --once`; scripts in the session scratchpad, nothing written to the repo); INFERENCE = reasoned, not run. Note: `vendor/` has Laravel 11.46.1, not 12.

## 1. What "sync domains to Halo" actually runs

1. Settings > Halo modal > Domains tab > tick domains > `syncHaloDomains()` (resources/views/admin/settings/index.blade.php:1601).
2. `POST /admin/sync/halo/domains/sync` (routes/web.php:134) > `SyncController::syncHaloDomains` (SyncController.php:153) > `SyncDispatcher::pushHaloDomains($ids, null, true, 'asset')` (SyncDispatcher.php:70).
3. That creates a `sync_runs` row (scope `domains`, status `queued`), dispatches `SyncHaloDomainsJob`, and returns 202.
4. The worker runs `SyncHaloDomainsJob` (fan-out): it sets the run total, then dispatches `PushDomainsToHaloJob` in chunks of 25 (SyncHaloDomainsJob.php:419-421).
5. Each chunk calls `HaloDomainPusher::push`: asset type lookup, site lookup, then POST /api/asset.

What the UI shows afterwards: a modal "Sync Queued ... Status updates as the queue worker processes them" (index.blade.php:1629-1637), then a page reload.
- FACT: nothing polls `/admin/sync/runs/{id}`. The response's `run` object is ignored.
- FACT: `SyncDispatcher::status()` (SyncDispatcher.php:116-135) reports only the `clients` and `assets` runs. The `domains` run is never surfaced anywhere.
- FACT: `halo_sync_error` is returned by the API (SyncController.php:144) but no view renders it (grep of resources/ and app/ finds no other use).
- FACT: `sync_runs.error` is never shown for any scope.

Other Halo entry points:
- "Refresh now" (clients index bar, settings sync bar) > `/sync/halo/refresh` > `refreshHalo` (clients job + assets job). The UI just says "Refresh queued" (clients/index.blade.php:712-730) or "Queued - reopen shortly" (settings:1427).
- Per-client Refresh now, Import, Link domains and Sync DNS > `refreshHaloClient` / `pushHaloDomains(mode=dns)`.
- Scheduler: `ScheduleRegistrar` registers clients every 6h, assets hourly, and a full assets run at 03:15, plus an optional `domaindash:sync-task sync-halo-assets` (settings-driven) that builds `Bus::chain([clients, assets, domains(force=false)])`.
- Every one of these only enqueues. Nothing runs without a `queue:work` process, and the schedule needs `schedule:run` from cron.

## 2. Root causes, ranked by likelihood

### R1. No queue worker running (or it died) - very likely
- FACT: `SyncHaloDomainsJob` and `PushDomainsToHaloJob` only execute under `queue:work`. The README (lines 158-160) and docs/review/10 say a worker and cron are required, but nothing in the UI or app checks for them. The 10-verification doc admits the worker and scheduler were never run end to end.
- Worker dying: queue:work exits when it hits `--max-time=3600` (README suggestion) or a job timeout (R5). Without Supervisor or systemd `Restart=always`, it stays dead.
- Wrong user: if the worker or cron runs as root against a www-data-owned `storage/`, files it creates (logs, cache, views) are root-owned. www-data then cannot write them and web requests may 500. The reverse also happens.
- Wrong queue: README suggests `INTEGRATIONS_QUEUE=integrations` with `--queue=integrations,default`. A worker started plainly (`queue:work`) listens only on `default`. If `INTEGRATIONS_QUEUE` is set in `.env` and the worker is not told, every Halo job sits forever (config/integrations.php:475).
- Stale worker: a worker started before a deploy runs old code. Without `php artisan queue:restart` it never picks up the new job classes or settings. If the autoloader is classmap-authoritative, new classes are "not found". `queue:restart` needs a working cache.

Checks (run in the app dir as the app user, e.g. `sudo -u www-data`):
```
php artisan about | grep -iE "queue|cache|environment"
php artisan tinker --execute='echo config("queue.default")," ",config("integrations.sync.queue")," ",config("cache.default");'
ps aux | grep -E "queue:work|schedule:run" | grep -v grep
systemctl list-units | grep -iE "queue|worker|horizon"; supervisorctl status; crontab -l -u www-data; crontab -l; cat /etc/cron.d/* 2>/dev/null | grep artisan
php artisan tinker --execute='echo DB::table("jobs")->count()," pending, oldest age(s): ", time()-(int)DB::table("jobs")->min("created_at"), PHP_EOL; echo DB::table("jobs")->select("queue", DB::raw("count(*) c"))->groupBy("queue")->get();'
php artisan queue:failed
```
Interpretation:
- Rows in `jobs` that are minutes old with `attempts=0` means no worker, or a worker on a different queue. Compare `jobs.queue` with the `--queue=` argument in the worker command line.
- Quick proof: run `sudo -u www-data php artisan queue:work --stop-when-empty -vvv` once and watch.

### R2. Halo not configured or settings unreadable: runs end `skipped`, silently - likely
- FACT: all sync jobs call `HaloPsaClient::isConfigured()`, and if it is false they `$run->skip('HaloPSA is not configured.')` (SyncHaloDomainsJob.php:385).
- FACT: if Settings decrypt fails (wrong or changed `APP_KEY`, or a config cached under a different key), `EncryptedSettingValue::get` returns null (Casts/EncryptedSettingValue.php:33-36). Halo then looks "not configured".
- Reproduced: a worker run with no Halo setting gave status `skipped`, error "HaloPSA is not configured.", and the UI shows nothing.
- Check: `php artisan tinker --execute='print_r(array_map(fn($v)=>$v?"set":"empty", App\Models\Setting::get("halo",[])));'` as the worker's user. Then `select id,scope,status,error,created_at from sync_runs order by id desc limit 20;`

### R3. Orphan "queued" runs created when ShouldBeUnique silently drops the dispatch - FACT, reproduced
- `SyncDispatcher` creates the `SyncRun` first and dispatches second (SyncDispatcher.php:28-29, 34-35, 76-77). `SyncHaloClientsJob` (uniqueFor 1800), `SyncHaloAssetsJob` (1800) and `SyncHaloDomainsJob` (600, unique per id-set) are `ShouldBeUnique`. A dispatch while the lock is held is discarded with no error.
- Reproduced three ways:
  - Pushing the same domain ids twice within 10 minutes produced 2 runs and 1 job. Run 2 stays `queued` forever. This is the likely path for a user who gets no feedback and clicks again.
  - A scheduled clients or assets job holding the lock, followed by "Refresh now", produced a `queued` run with no job.
  - After a worker drained everything, `SyncRun::active('halo','clients')` still returned the orphan (run id 3, `queued`).
- `SyncRun::active()` (SyncRun.php:258-270) treats any queued/running run younger than `stale_run_minutes` (120) as live. Every later `refreshHalo` or `refreshHaloClient` (and domain pushes with `domainIds === null`) returns the stale run and dispatches nothing, for up to 2 hours. The UI still says "queued".
- A lock can also stay held after a crash or kill until `uniqueFor` expires (10 or 30 minutes).
- `Bus::chain(...)->dispatch()` (scheduleHaloPipeline) does NOT honour uniqueness (2 chains produced 2 jobs). Scheduled `$schedule->job()` does honour it.
- Check:
```
select id,provider,scope,client_id,status,error,created_at,updated_at from sync_runs where status in ('queued','running') order by id desc;
php artisan tinker --execute='echo DB::table("cache_locks")->get();'   # laravel_unique_job:* keys (look at expiration)
```

### R4. Failures are retried or recorded invisibly - FACT, reproduced
- Reproduced with an unreachable Halo host: `PushDomainsToHaloJob` logged `DONE`, the run stayed `running` (0 done, 0 failed, `error` null), nothing in `failed_jobs`, and a delayed retry was queued.
- `PushDomainsToHaloJob::requeue` (lines 505-517) re-dispatches with 30s, 60s, 120s... capped at 900s, up to 8 rounds. That is about 45 minutes of apparent "running" before `failAll` writes a message to `domains.halo_sync_error`, which no view shows.
- The same applies to 429/5xx/timeouts, and to `TracksSyncRun::releaseOrThrow` for the clients and assets jobs (up to 12 releases, `retryUntil` 6h). No error text is saved to `sync_runs` while retrying.
- Non-retryable per-domain problems (no client, client not linked, missing expiry date, no "Domain" asset type, 4xx from Halo) go only to `halo_sync_error` and to the run's `failed` counter.
- Auth failure (401/403/bad client secret) is "non-retryable", so every domain gets `halo_sync_error` and the run ends `failed`. It is shown nowhere.
- Check: `select name,halo_sync_error,halo_synced_at from domains where halo_sync_error is not null limit 20;` and `tail storage/logs/laravel.log`.

### R5. Job timeouts and retry_after kill the worker or loop forever - INFERENCE (code-based)
- `queue.php:42` has `retry_after=90`. Jobs set no `$timeout`, and the README worker command has no `--timeout`, so the default is 60s.
- `SyncHaloAssetsJob` walks every page in one execution (100 per page, up to 4 attempts per request with sleeps). With thousands of assets this can exceed 60s. `PushDomainsToHaloJob` handles 25 domains with up to 4-6 sequential Halo calls plus a possible Synergy WHOIS SOAP call each, so it can also exceed 60s.
- On timeout, with `pcntl` loaded (it is in this environment), the worker kills itself with exit 1. Without a supervisor nothing restarts it, which feeds R1.
- The job is re-released after 90s. `TracksSyncRun` has `tries=0`, so the clients and assets jobs retry for 6 hours, each time dying at 60s, with the run stuck `running`.
- `PushDomainsToHaloJob` has `tries=3`. After 3 timeouts, `failed()` calls `failAll` on all 25 ids, which also counts already-successful ones, so run counters over-count.
- The unique lock stays held throughout.
- Check: `grep -n "timeout\|max-time" /etc/supervisor/conf.d/* /etc/systemd/system/*queue* 2>/dev/null` and `journalctl -u <worker> | grep -i "timed out\|killed"`.

### R6. Chain breaks for the scheduled pipeline - lower
- In `Bus::chain([clients, assets, domains])`, if an earlier job throws terminally or is released for hours (R4/R5), the domain push never starts. Config and auth failures call `$run->fail()` and return normally, so the chain continues and the push then fails again. The scheduled push uses `force=false`.
- The manual UI path is not chained, so this is not the user's immediate problem.

### R7. Smaller items
- Domains with no client are selectable in the modal ("No Client"). The job marks them failed ("No client assigned") invisibly.
- Cache requirement: `Cache::lock`, `RateLimited`, `WithoutOverlapping`, unique locks, the Halo token lock and `onOneServer` need a lock-capable store (`database`, `redis`, `file`, `memcached` all work, but not `array`/`null`). `CACHE_STORE=database` needs the `cache` and `cache_locks` tables (the migration exists).
- If `config:cache` was run with `QUEUE_CONNECTION=sync`, the "queued" jobs run inline in the web request instead. Run `php artisan config:clear` and confirm `config('queue.default')`.
- `jobs` payloads from before commit 314f7d9 (older class names) fail on deserialisation. They land in `failed_jobs` or loop. Check `php artisan queue:failed`.
- Sync routes (routes/web.php:131-150) have no `permission:sync.run` middleware, unlike `domains/bulk-sync`. This is a security note and not related to the symptom.
- RateLimited(`halo-api`) counts job executions, not HTTP calls, so it is unlikely to release jobs in practice. Rate limiter registration is in AppServiceProvider.php:63.

## 3. Halo API calls vs documentation

No official Halo API documentation was reachable. haloitsm.com/apidoc/info 301s to usehalo.com (marketing page), and the swagger URLs are not exposed publicly. Verification is therefore from the HaloAPI PowerShell module (homotechsual) and community docs. Treat anything not listed as verified as unverified, and confirm against your tenant's own `<tenant>/apidoc`.

| Call in code | Status | Evidence / note |
|---|---|---|
| Token: `POST {auth}/token?tenant=`, form body `grant_type=client_credentials`, `client_id`, `client_secret`, `scope=all` (HaloPsaClient.php:129+) | Verified | HaloAPI Connect-HaloAPI.ps1 (raw.githubusercontent.com/homotechsual/HaloAPI/main/Public/Connect-HaloAPI.ps1): form-encoded POST, scope default `all`, `tenant` query, fallback `{URL}/auth/token`. wyre-technology api-patterns page (claudepluginhub.com) says `tenant` is required for cloud-hosted. The module also derives the auth URL from `GET /api/authinfo` (`auth_url`); this code defaults to `{base}/auth`, which matches the fallback. |
| List paging: `pageinate=true`, `page_size`, `page_no`, `record_count` plus entity-named list key (paginate(), line 269) | Verified (params and shape) | HaloAPI Get-HaloAsset aliases (`pageinate`, `page_size`, `page_no`). The api-patterns page shows `{"record_count":N,"tickets":[...]}` and that `page_no` is 1-based. Default page_size is 50. |
| `GET /api/asset?client_id&assettype_id` | Verified | Get-HaloAsset parameters (`client_id`, `assettype_id`). |
| `search`, `includeinactive` on assets and clients | Verified for assets | Both are Get-HaloAsset parameters; wire key name for `search` is inferred (PS wrapper). Client `order=name` and `includeinactive` are unverified. |
| Asset `lastupdatefromdate` (HaloPsaClient.php:29, 404) | Parameter exists, semantics unverified | Get-HaloAsset exposes `lastupdatefromdate` / `lastupdatetodate` (docs page: no description, so format is unknown). The code already flags it as unverified. If Halo ignores it, the hourly "incremental" run is a full listing, which is only a performance issue. The format `Y-m-d\TH:i:s` (UTC) is unverified. |
| `GET /api/assettype`, name == "Domain" | Endpoint verified | A `Get-HaloAssetType` cmdlet exists (docs page listed in search). Whether a tenant has a "Domain" type is tenant data. If not, runs fail with "No Domain asset type". |
| `POST /api/asset` with a JSON array, `id` inside to update | Verified | HaloAPI New-HaloPOSTRequest uses `ConvertTo-Json -AsArray` (powershellgallery.com HaloAPI 1.23.2). The api-patterns page says POST bodies are always arrays and updates reuse POST with `id`. |
| Asset body: `client_id`, `site_id`, `assettype_id`, `inventory_number`, `key_field`, `key_field2`, `key_field3`, `notes` | Unverified | No schema was reachable. The payload also sends duplicates (`clientid`, `siteid`, `site_name`), which are harmless if ignored. |
| Custom `fields: [{id:148,value}, ...]` (HaloDomainPusher.php:99-104) | Unverified, and fragile | Field ids 148/149/150/165 are hard-coded and tenant-specific; I could not confirm the `{id,value}` shape. Wrong ids in a different tenant would be rejected or ignored. Recommend resolving ids from the asset type's field definitions (`/api/field`) or configuring them. |
| `GET /api/client/{id}` for `site_id`/`sites` | Unverified | Field names (`site_id`, `sites`) are guessed with fallbacks. A Halo client record's default site may come from `/api/site?client_id=` instead. If `site_id` is null the asset POST may fail on a mandatory site. |
| Error handling: 429/5xx retry; 401 re-auth once | Not API-doc dependent | Matches normal OAuth behaviour; Retry-After header handling is unverified. |

## 4. Recommended fixes

### Ops (do first)
1. Run the checks in sections R1 and R2. Decide where the worker runs, and run it as www-data under a supervisor:
```
[Unit] / [Service]
User=www-data
WorkingDirectory=/var/www/domaindash
ExecStart=/usr/bin/php artisan queue:work --queue=default --tries=3 --timeout=300 --sleep=3 --max-time=3600
Restart=always
```
   Add `--queue=integrations,default` only if `INTEGRATIONS_QUEUE=integrations`. Keep `DB_QUEUE_RETRY_AFTER` greater than `--timeout` (for example 600).
2. Cron for `www-data` (not root): `* * * * * cd /var/www/domaindash && php artisan schedule:run >> /dev/null 2>&1`.
3. After every deploy: `php artisan optimize:clear && php artisan config:cache && php artisan queue:restart`.
4. Clear stuck state once: delete or fail the stale `sync_runs` rows (`update sync_runs set status='failed', error='manual reset', finished_at=now() where status in ('queued','running')`) and release stale unique locks (`delete from cache_locks where \`key\` like '%unique_job%'`).
5. Don't use `sync` as `QUEUE_CONNECTION` in production.

### Code
- SyncDispatcher: dispatch and create the run atomically. If the unique lock is held, do not create a new run. Return the existing active run, or mark the new run `skipped` with "already queued/running". Remove `ShouldBeUnique` and rely on `SyncRun::active()`, or give the dispatched job the existing run id.
- `SyncRun::active()` should ignore `queued` runs whose job is gone: compare `created_at` with the oldest pending job age, and shrink `stale_run_minutes` for `queued` (for example 10) versus `running`.
- Stale-run recovery: a scheduled command (every 5 minutes) that fails any `queued` run older than 15 minutes with no matching row in `jobs`, and any `running` run with `updated_at` older than 30 minutes. Write a clear `error` ("No worker picked this up" or "Worker stopped responding").
- Record errors while retrying: in `releaseOrThrow` and `PushDomainsToHaloJob::requeue`, save `error = last exception message` and a `retry_at` on the `SyncRun`. Add `$timeout` (for example 240) to every sync job, and push chunk size or time budgeting so a chunk fits.
- Push job: on timeout or kill, only fail ids not already processed, to avoid double counting.
- Surface domain runs in `status()` (include scope `domains`), and show `halo_sync_error` per domain in the modal and on the domain/client pages.

### UX
- After POST, the modal should show a live progress panel polling `/admin/sync/runs/{id}` every 2-3s (queued, running done/total, failed, `error` text), with a link to failures. Do not reload on close. Same for the "Refresh now" buttons.
- Worker-not-running detection: show a banner when the oldest pending job in `jobs` is older than ~2 minutes (`time() - min(created_at)`), or when a heartbeat is stale. Heartbeat: a `schedule:run`-dispatched `WorkerHeartbeatJob` every minute writes `Cache::put('queue:heartbeat', now())`; the UI warns if older than 3 minutes. Also warn on failed jobs: `failed_jobs` count and latest exception.
- Preflight on the POST: return 422 with a clear message when Halo is not configured, when selected domains have no client or no expiry date, or when the worker looks down.
- Safe "Run now" path for admins with no worker: a button that executes `Artisan::call('queue:work', ['--stop-when-empty'=>true, '--max-time'=>50])` is risky inside php-fpm (the request timeout, shared lock contention). Safer options: (a) an artisan command `domaindash:sync-now halo` run over SSH, which calls `SyncHaloDomainsJob::dispatchSync(...)` and prints per-domain results; (b) a throttled admin button that dispatches with `dispatch_sync` only for small selections (at most 5 domains), with the error returned inline; (c) the documented systemd unit above.
- Update README and docs/review/10 to say "Run `queue:work` as www-data under a supervisor".

## 5. Evidence summary (scratch reproductions)
- 2 identical domain pushes: 2 `sync_runs`, 1 job, run 2 stuck `queued`.
- Scheduled clients job holding the lock, then Refresh now: clients run is `queued` with no job, assets run dispatched.
- Worker drained, orphan `queued` run still returned by `SyncRun::active`.
- Unconfigured Halo: runs `skipped`, error stored only in `sync_runs`.
- Unreachable Halo host: job `DONE` in ~4.5s, run `running`, delayed retry queued, no `failed_jobs` row, no error on run or domain.
- `Bus::chain` ignores unique locks; `Schedule::job` honours them.
- `phpunit --filter SyncRedesign`: 7 tests pass.
