# Task 6 - Security Audit (DomainDash)

Scope: static review of /home/user/DomainDash at commit 20d58d2 (Laravel 11.46.1, Jetstream 5.3.8, Fortify, Sanctum 4.2, Passport 12.4, spatie/permission 6.23, Livewire 3.6.4). Read-only; no code was executed against a running instance (`vendor/autoload.php` is absent so `artisan route:list` could not run; route analysis is from routes/*.php). Findings are tagged **[Confirmed]** (seen in code) or **[Speculative]** (depends on deployment/runtime not visible in repo).

## 1. Executive summary

The platform has a reasonable skeleton (permission middleware on admin routes, a scoped custom API-key layer, a Halo ticket flow that correctly scopes by client), but tenant isolation and secret handling have serious gaps:

1. **Tenant isolation is missing on the customer-facing DNS routes** (`/domains/{domain}/dns*`): no policy/ownership check, so any authenticated user can read any client's DNS zone, and any holder of `dns.manage` can modify any client's DNS. Self-registration is enabled (Fortify `Features::registration()`), `User` does not implement `MustVerifyEmail`, so the `verified` middleware passes for anyone who signs up. Net effect: an anonymous internet user can register and enumerate every client's DNS records by incrementing the domain ID.
2. **Vendor and customer secrets are stored and logged in plaintext**: settings table (Halo/Synergy/ITGlue/ip2whois/SMTP/backup/Vocus credentials) has no encryption; the audit log records settings diffs and raw request payloads (nested secrets are not redacted); hosting (cPanel) passwords and SSO URLs are written to `laravel.log`.
3. **Custom API keys** are bcrypt-hashed and scoped (good) but: unauthenticated requests cost O(N keys) bcrypt verifications (DoS), failed auth is not rate limited, no expiry/last-used, null scopes means *all* scopes, keys are global (not client-scoped), and a `users.write` key can mint an Administrator.
4. **MFA is not enforced** for anyone, including administrators; `EnsureMfaPolicy` only sets a session flag for a UI prompt. Impersonation has no audit trail of the real actor.
5. **No security headers, no trusted-proxy config, session cookie not forced Secure**, and `.env.example` defaults to `APP_ENV=local`, `APP_DEBUG=true`, `LOG_LEVEL=debug`.
6. **Dependencies**: `composer audit --locked` reports 54 advisories in 17 packages (incl. laravel/framework 11.46.1, livewire 3.6.4, guzzle 7.10.0, phpseclib 3.0.47, league/commonmark, symfony/*). `npm audit --omit=dev` is clean; dev-only toolchain has 22 (2 critical) advisories.

No command injection, SQL injection, or classic file-upload RCE was found (details in section 4).

## 2. Findings

Severity reflects realistic exploitability given the code as shipped.

| ID | Sev | Location | Title |
|---|---|---|---|
| SEC-01 | Critical | routes/web.php:41-45; app/Http/Controllers/DnsController.php:15,72,105,140,158 | IDOR: DNS read/write with no ownership check |
| SEC-02 | High | config/fortify.php:147; app/Models/User.php (no MustVerifyEmail); app/Actions/Fortify/CreateNewUser.php | Open self-registration yields an authenticated, "verified" account (enables SEC-01) |
| SEC-03 | High | app/Http/Controllers/Admin/ServicesController.php:283-290, 340-346 | Hosting passwords and cPanel SSO URL written to application log |
| SEC-04 | High | app/Http/Middleware/AuditRequestActions.php:50-71; app/Http/Controllers/Admin/SettingsController.php:201-213 | Secrets written to audit_logs in plaintext (nested payload keys + settings diff) |
| SEC-05 | High | app/Models/Setting.php:9-18; migration 20251113020801 | Vendor credentials stored unencrypted in `settings.value` |
| SEC-06 | High | app/Http/Middleware/ApiKeyAuth.php:19-22 | Unauthenticated bcrypt-scan DoS and no brute-force throttle on API key auth |
| SEC-07 | High | app/Http/Controllers/Api/UserController.php:28-48,57-72 | `users.write` API scope allows creating/promoting Administrators (no role allow-list, no MFA) |
| SEC-08 | High | app/Http/Middleware/EnsureMfaPolicy.php:38-66; app/Http/Controllers/Admin/UsersController.php:51,116 | MFA never enforced (admins included); `disabled` preference bypasses it |
| SEC-09 | High | app/Http/Controllers/TicketController.php:1032-1041; resources/views/tickets/show.blade.php:122 | Stored XSS via regex-based HTML "sanitizer" for Halo ticket content |
| SEC-10 | High | composer.lock | Vulnerable dependencies (54 advisories; Laravel 11.46.1, Livewire 3.6.4, Guzzle 7.10.0, phpseclib 3.0.47, commonmark, symfony/mime) |
| SEC-11 | Medium | app/Http/Controllers/Admin/DomainController.php:33,46,70; routes/web.php:57-63 | Customer-role users get all client names; non-"Customer" roles with `domains.view` see all domains |
| SEC-12 | Medium | app/Http/Middleware/ApiKeyAuth.php:37-41; app/Models/ApiKey.php:47-49 | API rate limit keyed on IP too; null scopes = unrestricted; keys global, no expiry |
| SEC-13 | Medium | app/Http/Controllers/Admin/UsersController.php:217-224; app/Http/Middleware/ApplyImpersonation.php | Impersonation not audited as such; actions attributed to victim; no MFA step-up; can impersonate inactive users |
| SEC-14 | Medium | app/Http/Controllers/UserSettingsController.php:37-52,56-85; UsersController.php:149-161 | Email/password change without session invalidation; email change needs no password/MFA; admin password set has no sessions revoked |
| SEC-15 | Medium | repo-wide (no headers middleware); bootstrap/app.php | No CSP / HSTS / X-Frame-Options / Referrer-Policy / X-Content-Type-Options |
| SEC-16 | Medium | bootstrap/app.php (no trustProxies); ApiKeyAuth.php:25; FortifyServiceProvider.php:57-61 | [Speculative] Behind a reverse proxy, `$request->ip()` is the proxy: breaks IP allowlist, shared login throttle bucket |
| SEC-17 | Medium | .env.example:2-4,17; config/session.php:172 | Insecure defaults: APP_ENV=local, APP_DEBUG=true, LOG_LEVEL=debug, SESSION_SECURE_COOKIE unset, SESSION_ENCRYPT=false |
| SEC-18 | Medium | resources/views/admin/settings/index.blade.php:765,847,943 | ITGlue, ip2whois and SMTP password rendered in cleartext into page HTML |
| SEC-19 | Medium | resources/views/admin/settings/index.blade.php:1448-1488,1553-1560; admin/services/internet/qualify.blade.php:171,278 | DOM XSS: externally-sourced strings (Halo names, errors, addresses) interpolated into `innerHTML` |
| SEC-20 | Medium | app/Http/Controllers/Api/DomainController.php (route routes/api.php:22) | `domains.read` scope returns EPP/auth codes (transfer-capable secret) |
| SEC-21 | Medium | app/Console/Commands/BackupRunCommand.php:27-36,52-60 | Backup: DB password on command line (`ps`), SFTP without host-key pinning, dump contains plaintext secrets |
| SEC-22 | Low | app/Http/Controllers/Api/UserController.php:44-46; ApiKeysController | API/user/key changes not individually audited with before/after; API user delete has no self/last-admin guard |
| SEC-23 | Low | ApiKeyAuth.php:73-78 | `cidrMatch` is IPv4-only, no mask validation (IPv6 client may match unexpectedly) [Speculative] |
| SEC-24 | Low | app/Http/Controllers/Api/*.php (`per_page`) | Unbounded `per_page` on list endpoints (UserController.php:22, AuditLogController.php:46) |
| SEC-25 | Low | ApiKeyAuth.php:46-57 | API access log skipped for rejected requests (401/403/429) and for exceptions |
| SEC-26 | Low | config/jetstream.php:64-65 | Jetstream Teams + account deletion enabled though unused (extra attack surface for self-registered users) |
| SEC-27 | Low | app/Http/Controllers/Admin/SettingsController.php (halo.base_url, auth_server, vocus urls) | Admin-configurable outbound URLs (SSRF-capable, admin only) |
| SEC-28 | Low | app/Http/Controllers/Admin/AuditLogController.php:111-123 | Admin can prune audit logs on demand; no tamper-evidence/off-box shipping |
| SEC-29 | Low | app/Http/Controllers/Admin/DashboardController.php:126 | `shell_exec('nproc')` - static string, not injectable (informational) |

### Detail

**SEC-01 [Confirmed] Critical - DNS IDOR.** `DnsController::index(Domain $domain, ...)` uses implicit route-model binding by raw ID (`routes/web.php:41`); no `authorize()`, no scoping to `$user->clients()`. `store/update/destroy/updateOptions` (lines 72,105,140,158) rely only on `permission:dns.manage` and also never check the domain's client. The route comment says "customer-visible for assigned domains" but nothing enforces it. `DomainPolicy::view` exists and is used by `Admin\DomainController::show` but not here.
*Exploit:* any logged-in user requests `GET /domains/1..N/dns` and reads each zone (Synergy `listDNSZone`) and nameservers. Any user who is granted `dns.manage` (the Technician role has it by default - RolePermissionSeeder line 36; admins can also give it to Customer via the permissions UI) can add/delete records or change nameservers for any client's domain, enabling mail interception, phishing, or domain takeover.
*Fix:* `$this->authorize('view', $domain)` in `index` and a new `manageDns` ability in `DomainPolicy` for the write actions, checking `client_id` membership for non-staff; or resolve via `Domain::whereIn('client_id', $user->clients()->pluck('id'))`. Apply the same ownership check anywhere a Domain/HostingService/SslCertificate is bound by ID for non-admin roles.

**SEC-02 [Confirmed] High - open registration.** `Features::registration()` is on; `User` has `// use MustVerifyEmail` commented out and Fortify `emailVerification()` is off, so the `verified` route middleware is a no-op (Laravel only blocks `MustVerifyEmail` users). `register.blade.php` exists. Self-registered users have no role, but reach every route in the `['auth','verified','mfa.policy']` group that lacks its own check (SEC-01, `/me/*`, `/notifications`). No password policy beyond `Password::default()` (min 8, no `Password::defaults()` override found).
*Fix:* remove `Features::registration()` (this is an invite-only MSP portal), or implement `MustVerifyEmail` + admin approval. Set `Password::defaults(fn() => Password::min(12)->mixedCase()->numbers()->uncompromised())`.

**SEC-03 [Confirmed] High - secrets in logs.** `ServicesController::password()` logs `'full_response' => json_encode($result)` where `$result['password']` is the cPanel password (lines 283-290); `login()` logs `json_encode($result)` including the SSO URL/token (340-346); line 467 logs the hosting package API response. Default `LOG_LEVEL=debug`, single file `storage/logs/laravel.log`. Anyone with log access (or a leaked log via SEC-17 debug pages) gets customer hosting credentials.
*Fix:* log only `status`/`has_password`; never log response bodies from credential endpoints; add a Monolog processor that redacts `password|token|api_key|secret|url`.

**SEC-04 [Confirmed] High - audit log leaks secrets.** `AuditRequestActions` runs on every non-GET web and API request and stores `query` and `payload`. Redaction (`except($sensitiveKeys)`) only covers top-level keys, so `POST /admin/settings` with `halo[api_key]`, `synergy[api_key]`, `smtp[password]`, `vocus[cert_password]`, `backup[password]`, `itglue[api_key]`, `ip2whois[api_key]` is stored verbatim (the "********" sentinel is only a display mask). `SettingsController` additionally writes `old_values`/`new_values` diffs (lines 201-213) containing the same secrets, so even a fixed middleware leaves them in the diff. The audit log is browsable by admins (`/admin/audit`) and exposed via API `audit.read`, widening access to vendor credentials. API request bodies (`/v1/users` password is top-level and is redacted, but nested fields are not).
*Fix:* recursive redaction using a deny-list of key names (case-insensitive, any depth); mask secret keys in settings diffs (record "changed" only); consider not storing payloads for settings routes at all.

**SEC-05 [Confirmed] High - plaintext credentials at rest.** `Setting` casts `value` to `array` (JSON), not `encrypted:array`; the migration is `longText`. Everything (Synergy reseller API key, Halo client secret, ITGlue key, SMTP and SFTP backup passwords, Vocus cert password) is readable by anyone with DB read, backups (SEC-21) or SQL injection elsewhere.
*Fix:* use `encrypted:array` cast (or encrypt only secret sub-keys), rotate all current credentials after migrating, and keep APP_KEY out of backups.

**SEC-06 [Confirmed] High - API key auth DoS.** `ApiKey::where('active', true)->get()->first(fn($k)=>password_verify($raw,$k->key_hash))` loads all active keys and runs bcrypt per request (~50-100 ms each) before any rate limiting; the limiter (lines 37-41) only runs after a *valid* key. An unauthenticated attacker can send garbage `x-api-key` values at will: CPU exhaustion proportional to key count, and unlimited online guessing (keys are 192-bit random hex so guessing is infeasible, but the DoS is real). The 401 path is also not throttled/logged to `api_access_logs`.
*Fix:* issue keys as `prefix.secret`; store `prefix` (indexed) and `hash('sha256', secret)` (high-entropy secrets do not need bcrypt) and compare with `hash_equals`; add `throttle` middleware by IP for the `api` group before auth. Current compare via `password_verify` is constant-time per key, so timing is acceptable; the issue is cost.

**SEC-07 [Confirmed] High - API privilege escalation.** `POST /api/v1/users` accepts any `role` string and creates an active user with a caller-chosen password and no MFA; `PUT /v1/users/{id}` can set `role=Administrator`. Any key holding `users.write` (or a legacy `write`/null-scope key, SEC-12) therefore equals full admin takeover of the web UI. Also `destroy` has no last-admin protection.
*Fix:* restrict API role assignment to a non-admin allow-list (`Technician|Customer`), force `mfa_preference=enforced`, require `password` reset on first login, and document `users.write` as admin-equivalent; consider removing user management from the API.

**SEC-08 [Confirmed] High - MFA not enforced.** `EnsureMfaPolicy` never blocks: for `enforced` users without MFA it only sets `mfa.setup` session data for the front-end modal (lines 44-51) and passes the request through. Livewire (`livewire/*`) and the `me.*` endpoints are exempt. `mfa_preference` can be set to `disabled` for anyone, including Administrators, by an admin (UsersController lines 51/116). No requirement that Administrator role implies MFA. Fortify's two-factor challenge only applies to users who have confirmed 2FA.
*Fix:* when `required` and not configured, redirect to a setup page for all non-exempt routes (HTML) / return 403 (JSON); force `enforced` for Administrator/Technician; disallow `disabled` for those roles; require recent password/MFA confirmation (`password.confirm`) for impersonation, MFA reset, API-key creation and settings changes.

**SEC-09 [Confirmed] High - stored XSS in ticket view.** `sanitizeActionHtml` uses regexes then `strip_tags` with an allow-list that keeps attributes. Bypasses: `<img/src=x/onerror=alert(1)>` (the `on*` regex needs leading whitespace), `<a href=javascript:alert(1)>` (unquoted; the javascript: regex requires quotes and the `[^\\2]*` is not a backreference), `style=`/`data:` URLs. Output is rendered raw with `{!! $actionDetailsHtml !!}`. Ticket replies are authored by customers (portal) and by third parties via Halo email, and are viewed by Administrators/Technicians with session-based power (impersonation, API key creation, settings).
*Exploit:* customer posts a reply containing the payload; an admin opens the ticket; script runs in the admin origin and can create an API key via `POST /admin/api-keys` and exfiltrate it from the flash value.
*Fix:* use a real sanitizer (e.g. `mews/purifier` / `HTMLPurifier` with an explicit allow-list and URI scheme allow-list) or render as escaped text only; add a CSP (SEC-15).

**SEC-10 [Confirmed] High - dependencies.** `composer audit --locked` (run in this session) found 54 advisories / 17 packages. Most relevant: `laravel/framework` 11.46.1 (CRLF injection in `email` validation rule - high; signed URL path confusion; debug-page XSS), `livewire/livewire` 3.6.4 (DOM XSS, fixed after 3.8.2), `guzzlehttp/guzzle` 7.10.0 (host-check bypass high + cookie/proxy issues; needs >=7.15.2) and `psr7` <2.12.3, `phpseclib/phpseclib` 3.0.47 (padding-oracle/timing + ASN.1 DoS; used by the SFTP backup), `league/commonmark` (multiple high DoS/XSS, transitive), `symfony/mime|mailer|routing|http-foundation` (header/SMTP injection), `phpunit` (dev), `psy/psysh` (dev). Some Laravel advisories list all 11.x as affected, so a move to Laravel 12 may be required for a full fix. `laravel/passport` 12.4.2 is installed but unused (Passport routes/guard not configured); remove it and `firebase/php-jwt`. `league/flysystem-sftp-v3: *` and `phpseclib: *` are unpinned wildcards. `npm audit --package-lock-only --omit=dev`: 0 vulnerabilities; with dev deps: 22 (2 critical, 17 high; vite/dev-server related, only relevant on developer machines or if `vite dev` is exposed).
*Fix:* `composer update` to patched versions, upgrade Laravel/Livewire, pin wildcards, add `composer audit` + `npm audit` to CI.

**SEC-11 [Confirmed] Medium - admin domain views.** `Admin\DomainController::index` restricts rows only if `hasRole('Customer')` (line 33); any other role with `domains.view` and `viewAny` (policy returns true if the user merely has a client link) sees *all* domains. Both `index` and `show` pass `Client::orderBy(...)->get()` (lines 46, 70) to the view for every role, leaking all client business names to Customers.
*Fix:* scope by "not staff" instead of "is Customer", and only load all clients for staff.

**SEC-12 [Confirmed] Medium - API key model.** Rate-limit bucket includes `$request->ip()` (a key leaked and used from many IPs multiplies the budget); `allowsScope()` returns true when `scopes` is null/empty (line 47-49) - the UI and API allow creating a key with no scopes (`'scopes' => 'nullable|array'`); legacy `read`/`write`/`services.*` wildcards are still honoured; no `expires_at`, `last_used_at`, owner, or client scoping (every key sees every client's data); `allowed_ips` defaults to allow-all. Revocation exists (deactivate/delete/regenerate) and keys are shown once (`new_api_key` flash, bcrypt-hashed at rest, 24 random bytes - good). Sanctum is installed but `personal_access_tokens` is not used by any route; the API relies solely on the custom layer.
*Fix:* require at least one scope; drop legacy wildcards after migrating; add expiry and last-used; rate limit per key (not per IP) plus per-IP throttle; optional `client_id` binding for scoped keys.

**SEC-13 [Confirmed] Medium - impersonation.** `ApplyImpersonation` swaps the authenticated user from `session('impersonate_as')`; `AuditLogger` records `Auth::user()` (the victim), so the real administrator is not recorded (no `impersonator_id`). `impersonate()` has no check for inactive users, no confirmation, no restriction on impersonating other administrators. Combined with the `mfa.policy` exemption for `admin.users.stop-impersonate` this is mostly fine, but it undermines non-repudiation.
*Fix:* log `impersonation.start/stop` with both IDs; add `impersonator_id` to every audit row while active; forbid impersonating Administrators/inactive users; show a persistent banner (present or not could not be verified).

**SEC-14 [Confirmed] Medium - credential change hygiene.** `UserSettingsController::updateAccount` changes the email with no current-password/MFA check and no re-verification (account takeover persistence from a hijacked session, plus password-reset redirection). `changePassword` and admin `updatePassword` do not call `Auth::logoutOtherDevices` / invalidate the `sessions` rows; `changePassword` has no throttle and a weaker rule (`min:8`) than a policy would give. Deactivating a user (`is_active=false`) is checked only at login (FortifyServiceProvider line 51-56); existing sessions remain valid.
*Fix:* require password confirmation for email change; delete the user's sessions on password/role/`is_active` change; add `AuthenticateSession` middleware and an `is_active` check in a middleware.

**SEC-15 [Confirmed] Medium - security headers.** grep finds no CSP, HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy anywhere in app/, bootstrap/, config/ or public/.htaccess. The heavy inline `<script>`/`style=` usage and `innerHTML` (SEC-19) mean CSP will need a nonce rollout.
*Fix:* global middleware (or web-server config) adding HSTS, `X-Frame-Options: DENY`/`frame-ancestors 'none'`, nosniff, strict referrer, and a nonce-based CSP.

**SEC-16 [Speculative] Medium - proxy trust.** No `trustProxies` call in `bootstrap/app.php`. If deployed behind nginx/ALB (typical), `Request::ip()` is the proxy address: API IP allowlists and per-IP throttles (login: 5/min keyed by email+IP) become shared or ineffective, and audit rows record the wrong IP. Conversely trusting `*` without a proxy allows `X-Forwarded-For` spoofing. Deployment config not in repo.

**SEC-17 [Confirmed] Medium - insecure defaults.** `.env.example`: `APP_ENV=local`, `APP_DEBUG=true` (stack traces and env on errors), `LOG_LEVEL=debug`, `SESSION_ENCRYPT=false`, no `SESSION_SECURE_COOKIE`, `MAIL_MAILER=log` (reset links go to log). `config/session.php`: `secure` => env(null) so cookies are not Secure over HTTPS unless set; `http_only` true and `same_site` lax are fine. `config/app.php` debug default is false (good). `.env` is git-ignored and no committed secrets were found (`git ls-files` shows no .env/keys; `database/sql/ddl.sql` and `add_sync_columns.sql` are schema only).
*Fix:* ship production-safe example (`APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `LOG_LEVEL=warning`).

**SEC-18 [Confirmed] Medium - cleartext secrets in HTML.** Synergy/Halo/Vocus use the `********` mask (good), but `itglue[api_key]` (line 765), `ip2whois[api_key]` (847) and `smtp[password]` (943) echo the real secret into the page, so it lands in browser cache/history, screenshots and XSS-readable DOM. Also `backup[password]` should be checked (not located in the views grep).
*Fix:* apply the same sentinel pattern to every secret field.

**SEC-19 [Confirmed] Medium - DOM XSS.** Examples: settings/index.blade.php:1448 (`${data.error}`), 1454 (`error.message`), ~1475-1488 (`client.halo_name`, `s.name`), 1560; internet/qualify.blade.php:171 (`addr.address_long`) and :278. Values originate from Halo/Vocus/third-party APIs (data a Halo operator or upstream can control) and are inserted via template literals into `innerHTML`. Requires attacker-controlled upstream data and an admin viewing the page, hence Medium.
*Fix:* build DOM with `textContent` or an `escapeHtml` helper.

**SEC-20 [Confirmed] Medium - auth codes via read scope.** `GET /api/v1/domains/{domain}/auth-code` is in the `domains.read` group (routes/api.php:22). An EPP code allows transferring the domain away; the web equivalent requires `domains.transfer`. A read-only monitoring key therefore holds transfer capability.
*Fix:* move to a separate `domains.auth-code` scope, audit every access.

**SEC-21 [Confirmed] Medium - backup process.** `system("mysqldump -u.. -p<password> ...")` exposes the DB password in the process list (escaped, so not injectable); `SftpAdapter` has no `hostFingerprint`/key pinning (MITM of backups, which contain all plaintext secrets - SEC-05); the SFTP password is stored in settings (SEC-05); uses only the `mysql` connection though default is sqlite (so backups may silently be broken - out of scope).
*Fix:* use `MYSQL_PWD`/defaults-file, pin host key, encrypt the archive before upload.

**SEC-22..29 Low:** see table. SEC-23: `ip2long()` returns false for IPv6 so `false & mask == ip2long($subnet)` can be true for subnets like `0.0.0.0/..`, and masks are unvalidated (speculative). SEC-27: Halo `base_url`/`auth_server` and Vocus `wsdl_url`/`login_url` accept any URL (validated `url` only for Vocus) and the app sends bearer credentials to them - admin-only SSRF/credential exfiltration to an attacker-chosen host if an admin account is compromised.

### Areas reviewed and found acceptable

- **SQL injection:** no `whereRaw`/`DB::raw` with user input; only a constant `orderByRaw` (EmailTemplateMailer.php:85). Sort columns are allow-listed (Admin\DomainController:21-27). `LIKE` searches are parameter-bound.
- **Command injection:** only `system()` in BackupRunCommand (all args `escapeshellarg`'d from config) and `shell_exec('nproc')`. There is no web-reachable dig/whois/ping; "diagnostics" are Vocus SOAP calls with a fixed enum (`AUTH-LOG|DISCONNECT`). ip2whois lookup is an HTTPS call to a fixed host with the domain as a query parameter (no SSRF by user host).
- **Mass assignment:** all models use explicit `$fillable`; `User::$fillable` includes `mfa_preference` and `is_active`, but every call site builds arrays from validated fields (no `$request->all()` into `create`/`update` seen). Role is assigned through `syncRoles` with an `in:` allow-list in the web UI.
- **CSRF:** no exemptions found; routes are in the `web` group; the API is key-based (no cookies).
- **Open redirect:** only `redirect()->away($url)` for the Synergy-provided cPanel SSO URL (server-sourced). Sort links use `http_build_query` (escaped).
- **File uploads:** `branding_logo` validated `image|max:2048` (SVG excluded by Laravel 11 `image` rule) stored with hashed name on `public`; `vocus_cert` validated by extension (p12/pfx/keystore), saved on the private `local` disk under a fixed name. Note: the extension check is client-supplied, no content sniff; the cert is overwritten as `client.<ext>`.
- **Ticket tenant isolation:** `TicketController` resolves the client from `availableClients()` (pivot-scoped unless Administrator), then compares the Halo ticket's client with the selected client's `halopsa_reference`; `store` validates the client and reference ownership. Good pattern - reuse for DNS/hosting.
- **Login rate limiting:** Fortify `login` (5/min per email+IP) and `two-factor` (5/min per session) limiters are defined. No limiter on password reset (Fortify default `throttle:6,1` is built-in for the reset-link; not verified) or on `/me/account/password`.
- **Web admin routes:** `/admin/*` administrator area is wrapped in `role:Administrator`; domain admin routes in `permission:*`. The `/admin/domains/{domain}` routes check the policy in the controller, but `renew`, `assignClient`, `authCode` rely on permission only (acceptable for staff roles).

## 3. Audit logging coverage

- Present: login/failed-login events, DNS record changes (with client_id), settings changes, domain/ssl actions, a catch-all `AuditRequestActions` for non-GET requests (web and API).
- Gaps: reads of secrets are not audited (hosting password reveal `ServicesController::password`, EPP code, SSL bundle); impersonation actor (SEC-13); password/MFA resets by admin are only visible via the generic HTTP row; API access log omits rejected calls (SEC-25); audit rows themselves contain secrets (SEC-04); audit pruning by admin is possible without trace beyond a single row (SEC-28); logout, 2FA enable/disable are not explicitly listed.

## 4. Hardening checklist

Immediate (days):
- [ ] Add ownership authorization to every customer-reachable route using `Domain`/`HostingService`/`SslCertificate` (SEC-01); add feature tests asserting a user of client A gets 403 on client B's resources.
- [ ] Disable public registration (SEC-02) and review existing `users` for roles-less accounts.
- [ ] Stop logging response bodies in ServicesController; rotate any hosting passwords/SSO URLs that have been logged (SEC-03).
- [ ] Recursive redaction in AuditRequestActions and settings diff; purge/redact existing `audit_logs.new_values/old_values` rows; rotate Halo, Synergy, ITGlue, ip2whois, SMTP, SFTP and Vocus credentials (SEC-04/05).
- [ ] Replace the ticket HTML sanitizer with HTMLPurifier (SEC-09).
- [ ] Set production env: `APP_DEBUG=false`, `APP_ENV=production`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `LOG_LEVEL=warning` (SEC-17).
- [ ] `composer update` / upgrade framework, Livewire, Guzzle, phpseclib; remove unused Passport and wildcard constraints (SEC-10).

Short term:
- [ ] `encrypted:array` (or per-field encryption) for `settings.value` (SEC-05).
- [ ] Re-engineer API keys: `prefix.secret` lookup, SHA-256 + `hash_equals`, IP throttle before auth, required scopes, expiry, last-used, per-key rate limit, optional client binding; separate scope for auth codes; restrict `users.write` roles (SEC-06/07/12/20).
- [ ] Enforce MFA (blocking) for Administrator and Technician; step-up confirmation (`password.confirm`) for impersonation, MFA reset, API keys, settings (SEC-08).
- [ ] Add security-header middleware incl. nonce CSP; configure `trustProxies`/`trustHosts` per deployment (SEC-15/16).
- [ ] Revoke sessions on password/role/active changes; add `AuthenticateSession`; require password for email change (SEC-14).
- [ ] Impersonation audit with real actor; forbid admin-on-admin (SEC-13).
- [ ] Mask all secret inputs in settings views (SEC-18); replace `innerHTML` interpolation with escaped DOM building (SEC-19).
- [ ] Customer views: scope by "non-staff" and stop loading all clients (SEC-11).

Ongoing:
- [ ] CI: `composer audit`, `npm audit --omit=dev`, PHPStan/Larastan with authorization rules, secret scanning.
- [ ] Ship audit logs off-box; make pruning require a second admin or be disabled.
- [ ] Pin SFTP host key, use `MYSQL_PWD`/defaults-file, encrypt backups (SEC-21).
- [ ] Unit/feature tests for tenant isolation across web and API (API is global by design - document it).
- [ ] Password policy: min 12, uncompromised check, lockout/alerting on repeated failures, throttle `/me/account/password` and password-reset endpoints.
- [ ] Remove unused Jetstream Teams/account-deletion features (SEC-26).

## 5. Limitations

Static analysis only; application was not booted (no vendor/autoload, no DB), so route middleware stacks, Fortify/Jetstream-provided routes, Blade rendering of `{!! !!}` helpers beyond those listed, and the actual presence of reverse-proxy/WAF headers were not verified. Livewire components were not found under app/ (none reviewed). The `app/Services/Synergy` and `Vocus` clients were reviewed only for logging and URL handling, not for protocol-level issues. Items marked [Speculative] need confirmation against the deployed environment.
