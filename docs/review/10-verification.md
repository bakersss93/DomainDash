# 10 - Final verification (Task 10)

Branch `claude/eloquent-hypatia-5lio42` (PR #57), compared with base `20d58d23` (merge-base with `origin/main`).
Verifier: independent re-run of tests, build, migrations, cache commands, route wiring and OpenAPI lint. No composer or state-changing git commands were used.

## 1. Verdicts

| Task | Verdict | Evidence / notes |
|---|---|---|
| 1/2/3 API clients (Halo, IT Glue, Synergy v3.18, Vocus WSM) | PASS WITH NOTES | Typed exceptions exist (`IntegrationApiException`, `SynergyApiException`, `VocusWsmException`); timeouts and rate limits are configurable in `config/integrations.php` / `config/services.php` (all `config()` keys read in `app/`, `routes/`, `resources/` and `database/` resolve to a defined key; only `permission.testing`, a Spatie test-only key, is undefined). Client unit tests pass. Not verifiable here: SOAP runtime (ext-soap is absent in this sandbox; `tests/Support/soap_polyfill.php` is a test shim), and every Halo behaviour except auth (the Halo apidoc was unreadable during task 1). Integration exception messages contain only the HTTP method, path and status; response bodies are kept in a separate property and not shown. |
| 4/5 Sync | PASS | Scheduler (`php artisan schedule:list`) shows Synergy, Halo clients (6h), Halo assets (hourly incremental, daily 03:15 full) and IT Glue organisations (6h) jobs. Constructors match the registrar calls (`SyncHaloAssetsJob(null, true)` etc.). The jobs run on `integrations.sync.queue`. `SyncController` reads `ItglueOrganization` / `HaloClient` local tables; the remaining live Halo calls are the ticket UI/API and the settings "test connection" button (by design). "Last synced" is shown on clients index, settings and domain pages; manual refresh routes `sync/halo/refresh`, `sync/itglue/refresh` and `sync/clients/{client}/{provider}/refresh` are throttled. |
| 6/7 Security | PASS WITH NOTES | All Critical/High items SEC-01..09 are fixed in code with tests in `tests/Feature/Security`. SEC-10 (vulnerable composer dependencies) is a documented deferral: it needs `composer update` plus `composer audit` on a machine with GitHub access. Medium items not confirmed fixed (see risks): SEC-16 trusted proxies, SEC-19 DOM XSS in other views. |
| 8 OpenAPI 3.1 | PASS (after drift fix) | 41 operations. The drift described below was fixed. `OpenApiDocumentTest` now also asserts that every operation's `x-required-scope` equals the route's `ApiKeyAuth` scope (7 tests, 102 assertions). Redocly lint: valid (1 warning, missing `license`). YAML and JSON are equal. Downloads are served at `/api-docs/openapi.{yaml,json}` for administrators. |
| 9 UI | PASS WITH NOTES | `npm run build` succeeds. Shared `<x-ui.*>` components have tests. Dark mode tokens are in place. The long-tail admin screens still use inline styles (documented as deferred in report 09). No browser or visual regression run was done by this verifier. |

## 2. Test, build and lint results

- `DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test`
  - Start of verification: 162 passed, 5 skipped, 1 failed (`ExampleTest`).
  - Final: **166 passed, 5 skipped, 0 failed, 599 assertions**.
  - The 5 skips are stock Jetstream feature-flag `markTestSkipped` calls (API tokens, account deletion, team invitations, password reset); not related to this work.
- `ExampleTest` failure (`GET /` returned 302): a **stale test, not a regression**. `Route::get('/')` has had `middleware(['auth','verified'])` and a redirect to `dashboard` since the base commit, and the test file is unchanged. Test rewritten to assert a redirect to `login` for guests.
- `npm run build`: vite 6.4.1, 53 modules transformed, built in 1.73s. Outputs: `app-*.css` 106.68 kB (18.81 kB gzip), `app-*.js` 39.53 kB (15.84 kB gzip). `npm audit --omit=dev`: 0 vulnerabilities.
- `npx @redocly/cli@1.34.5 lint resources/openapi/domaindash-openapi.yaml`: "Your API description is valid", 1 warning (info-license).
- `php artisan migrate:fresh` on a file-based sqlite DB: all 48 migrations ran in order, including the 8 new ones. `migrate:rollback` of the new batch (the 8 `2026_10_19_*` migrations, run as their own batch): all rolled back and re-applied cleanly. Rolling back the full history fails in the **pre-existing** migration `2025_11_23_033136_add_ip_address_to_hosting_services_table` (sqlite cannot drop an indexed column; the migration is unchanged from base and MySQL is unaffected).
- `php artisan about`, `config:cache`/`config:clear`, `view:cache`/`view:clear`, `schedule:list`, `event:list`, `route:list` (242 routes): no errors.
- Wiring check script (route to controller@method, route middleware class names): all resolve **except two dead routes**, see section 3.

## 3. Defects found and fixed during verification

| # | Defect | Fix (files) |
|---|---|---|
| D1 | Stale `ExampleTest` | `tests/Feature/ExampleTest.php` |
| D2 | Two routes pointed at methods that do not exist (`ClientsController@confirmImportHalo`, `@linkItglue`); calling them would give a 500. They pre-date this branch and no UI references them. Routes removed. | `routes/web.php` |
| D3 | OpenAPI drift vs the security work: `domains.auth-code` and `users.admin` scopes, `/clients/{client}/domains` now filtered by the path client (and 404), 403 for admin-targeted user calls, 422 for deleting the last administrator, null-scope means no access, `Retry-After` header on 429 (per key, per IP and failed-auth), always-JSON 422 on `api/*`, `per_page` max 100, key format `<prefix>.<secret>`, `API key expired`. YAML and JSON regenerated consistently (JSON generated from the YAML; round-trip of the original files was verified byte-identical first). | `resources/openapi/domaindash-openapi.yaml`, `.json`, `tests/Feature/OpenApiDocumentTest.php` (new scope-drift test) |
| D4 | Raw exception text shown to users: `DnsController` (refresh and options), `ServicesController::cpanelLogin`; `Api\DomainController` sync and renew, `Api\HostingController::sync`, `Api\SslController::sync` forwarded the registrar's `errorMessage` ("Synergy error: ..."). Now generic user messages; details go to the log / `report()`. | `app/Http/Controllers/DnsController.php`, `Admin/ServicesController.php`, `Api/DomainController.php`, `Api/HostingController.php`, `Api/SslController.php`, `tests/Feature/Security/ErrorLeakageTest.php` (new) |
| D5 | `clients/form.blade.php` concatenated server-provided strings (`data.message`, `data.error`, per-domain results, caught error messages) into `statusDiv.innerHTML`. Values now go through the page's existing `ddEscHtml` escaper (7 sites; innerHTML kept because the markup is built from fixed fragments). | `resources/views/admin/clients/form.blade.php` |

## 4. Adversarial review results (no defect found)

- **Migrations**: new schema migrations are additive. `verify_existing_users_email` and `encrypt_settings_values` are idempotent (encrypt skips `enc:v1:` rows; re-run after rollback confirmed).
- **Setting encryption**: `EncryptedSettingValue::get` accepts legacy plaintext JSON rows, so `Setting::get` stays backward compatible. Caveat: a wrong or rotated `APP_KEY` makes encrypted rows read as "not set" (returns null); a later `Setting::put` would then overwrite them. Do not rotate `APP_KEY` without re-saving the settings.
- **API key upgrade**: legacy keys were `bin2hex(random_bytes(24))` with bcrypt (verified in the base commit). `ApiKey::findByPlain` matches those (48 hex, no dot, `key_prefix` null), verifies with `password_verify`, and rewrites the prefix and SHA-256 hash on first use. Existing keys with **null/empty scopes lose all access** (previously unrestricted); this is intended but must be handled at deployment (see checklist).
- **MFA enforcement**: `EnsureMfaPolicy` is only on the authenticated web groups. Logout and Fortify routes are outside it; `profile.show`, `two-factor.*`, `me.mfa.*`, `password.confirm*`, `admin.users.stop-impersonate` and `livewire/*` are allow-listed; the dashboard hosts the setup prompt. Non-GET requests from an unenrolled admin to other pages are redirected to the dashboard. `MFA_ENFORCE=false` and `domaindash:mfa-reset` are the break-glass paths.
- **CSP**: report-only by default; policy allows `'unsafe-inline'`/`'unsafe-eval'` (needed by Alpine/Livewire), `fonts.bunny.net`, and Vite dev origins only when `APP_ENV=local`. Production build assets are same-origin, so enforcing should be safe, but it was not exercised in a browser.
- **Scheduler**: `ScheduleRegistrar` uses `Schema::hasTable('settings')` before reading settings; `onOneServer()` uses the cache lock table (the `cache_locks` migration exists). `schedule:list` on an unmigrated DB throws (environmental).
- **Removed methods/classes**: no references remain to any public/private function deleted in the diff; no files deleted.

## 5. Open risks

1. **SEC-10 dependencies** (Laravel 11.46.1, Livewire 3.6.4, Guzzle 7.10.0, phpseclib 3.0.47, etc.) are unfixed; `composer.lock` has not been refreshed. Some advisories may need Laravel 12.
2. **ext-soap is not declared in `composer.json`** and was never available in this sandbox: all Synergy/Vocus SOAP code is verified only through unit tests with a polyfill. It is required in production (and in CI).
3. **Halo API behaviour unverified** (pagination parameter names, `record_count`, asset filters, POST array-body contract, rate limits) because the Halo apidoc was unreadable; confirm against `<tenant>/apidoc` before relying on incremental sync.
4. **Rewst / existing API keys** with no scopes (or only legacy `read`/`write`) lose access or sensitive access (`domains.auth-code`, `users.*`, `audit.read`); re-scope before cutover.
5. SEC-16 (no `trustProxies`; IP allow-list and throttles see the proxy IP behind a reverse proxy) and SEC-19 (about 80 other `innerHTML` uses in views, only some audited) remain. `bootstrap/app.php` has no `trustProxies` configuration.
6. Remaining controllers still show `$e->getMessage()` to admins (Admin Internet/Purchase/Transfer/SSL/Hosting controllers, `TicketController`, `SyncController`, API `TicketController` returns the sanitized integration message). They are admin-facing or carry path/status only, but they have not been moved to generic messages.
7. CSP is report-only and allows unsafe-inline/eval; enforcement gives limited XSS protection until a nonce rollout.
8. No browser/visual check of dark mode or the CSP was done by the verifier; queue worker and scheduler were not run end to end (only registration and unit/feature tests).
9. Rollback of the full migration history is not possible on sqlite (pre-existing); the new migrations roll back fine.

## 6. Deployment checklist

1. On a machine with GitHub access: `composer update` (and `composer audit`); remove unused `laravel/passport` and pin the `*` constraints; commit `composer.lock`. Ensure the PHP runtime has **ext-soap**.
2. Back up the database and `APP_KEY`. Do not change `APP_KEY` afterwards (encrypted settings).
3. `php artisan migrate --force` (adds sync tables, API key columns, verifies existing users' email, encrypts settings in place). `npm ci && npm run build`. Then `php artisan config:cache route:cache view:cache`.
4. Run a **queue worker** for `INTEGRATIONS_QUEUE` (default `default`) and a **scheduler** (`* * * * * php artisan schedule:run`). Without them the Halo / IT Glue mirrors never populate. Trigger "Refresh now" once per provider after deploy.
5. Environment variables: `ALLOW_REGISTRATION=false`; `MFA_ENFORCE=true`, `MFA_REQUIRED_ROLES=Administrator` (every administrator must enrol 2FA at next login; `domaindash:mfa-reset <email>` and `MFA_ENFORCE=false` are break-glass); `CSP_ENFORCE=false` initially, check reports, then `true` (`CSP_REPORT_URI` optional); `INTEGRATIONS_QUEUE`; `SYNERGY_WSDL`, `SYNERGY_ENDPOINT`, `SYNERGY_SANDBOX`, `SYNERGY_SANDBOX_ENDPOINT`, `SYNERGY_CONNECTION_TIMEOUT`, `SYNERGY_TIMEOUT`, `SYNERGY_WSDL_CACHE`; `VOCUS_ENVIRONMENT`, `VOCUS_PRODUCTION_ENDPOINT` / `_LOGIN_URL`, `VOCUS_SANDBOX_ENDPOINT` / `_LOGIN_URL`, `VOCUS_CA_BUNDLE`, `VOCUS_CONNECTION_TIMEOUT`, `VOCUS_TIMEOUT`, `VOCUS_RATE_LIMIT_MAX` / `_SECONDS` / `_MAX_WAIT`, `VOCUS_SESSION_TTL`; production-safe `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`. Configure trusted proxies if behind a load balancer.
6. API keys: legacy keys keep working only if they carry scopes; re-scope the Rewst key (grant exact scopes, add `domains.auth-code` / `users.*` / `users.admin` / `audit.read` only if really needed, set an IP allow-list and expiry). Keys are upgraded to the prefix + SHA-256 format on first use; regenerate when convenient. Re-download the OpenAPI spec from the API Keys page for Rewst.
7. Credential rotation: rotate Halo, IT Glue, Synergy, Vocus, SMTP, ip2whois credentials and any hosting/cPanel passwords that were logged or stored unencrypted before this release (SEC-03/04/05); old audit-log rows and log files may still contain secrets, prune them.
8. Verify Halo behaviour at `<tenant>/apidoc` (see risk 3) and watch `sync_runs` / failed jobs after the first scheduled runs.
