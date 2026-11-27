# DomainDash

A modern, dark-mode friendly control panel for managing domains, DNS and clients — powered by Laravel and Synergy Wholesale.

---

## ✨ Features

### 🔐 Domain management

- **Domain list view**
  - Sortable by *name*, *status*, *expiry* and *DNS configuration* (click column headers to toggle ASC/DESC).
  - Inline expiry countdown (e.g. `233 days`) with **danger highlighting** when a domain is inside 30 days.
  - Friendly DNS config labels (e.g. `URL & Email Forwarding`, `DNS Hosting`, `Custom Nameservers`).
  - Quick options row:
    - **Overview**
    - **Nameservers & DNS**
    - **Renew**
    - **Assign client**
    - **Initiate transfer**
    - **Transactions**
    - **Password / auth code** (deep link to overview)
    - **Delete domain** (soft delete / archive by default)

- **Slide-down details panel**
  - Click a row to reveal a detailed “action panel” with rounded, pill-style buttons.
  - No extra vertical spacing when closed – the table stays compact like a standard registrar panel.

- **Bulk domain sync**
  - Syncs domains from Synergy using `listDomains`.
  - Creates or updates local `Domain` records with:
    - Status  
    - Expiry date  
    - Nameservers  
    - DNS configuration  
    - Auto-renew flag  
    - Transfer status  

---

### 👥 Client assignment

- **Assign client modal** (from the domain list)
  - Click the client icon to open a centred modal.
  - Searchable “combobox” style selector:
    - Start typing to filter by client name.
    - Uses a hidden `<select>` for clean form submission.
  - Updates the domain’s `client_id`.

- **Client / categories block on overview**
  - Same searchable dropdown, integrated into the domain overview page.
  - One client per domain, with clear “No client” state.

---

### 📄 Domain overview

A clean overview card layout for each domain:

- Domain information:
  - Name  
  - Status  
  - Expiry date  
  - Auto-renew state  
  - DNS configuration (friendly label, not just numeric codes)
- Nameserver information:
  - Currently linked nameservers (placeholder or real data from Synergy).
- Transactions panel (placeholder, ready for future integration).
- Client assignment card (as described above).

#### 🔑 EPP / auth code popup

- “Get auth code” button.
- Calls Synergy’s `domainInfo` and reads the `domainPassword` value.
- Shows the EPP code in a small modal with:
  - Read-only input.
  - **Copy to clipboard** button.
  - Close button + click-outside to dismiss.
- Errors are shown with a friendly alert message from the JSON response.

---

### 🌐 DNS & Nameserver management

From the **DNS records** page for each domain:

#### Records

- Always **pulls live DNS records** from Synergy via `listDNSZone` when the page loads.
- “Refresh records” button re-queries Synergy on demand.
- Compact table with pill-style inputs:
  - Hostname
  - Type (A, AAAA, CNAME, MX, TXT, …)
  - Content
  - TTL
  - Priority
- **Add record**:
  - Rounded pill inputs in the toolbar row.
  - Submits directly to Synergy via `addDNSRecord`.
- **Edit record**:
  - Implemented as **delete + re-add** using Synergy’s `deleteDNSRecord` + `addDNSRecord`.
- **Delete record**:
  - Single click to remove a record via `deleteDNSRecord`.
- If Synergy returns an error, the page safely shows an informative message instead of breaking.

#### DNS options / nameservers

- **DNS options** button in the toolbar opens a modal where you can:
  - Choose DNS mode:
    - `1` – Custom nameservers  
    - `2` – URL & Email Forwarding  
    - `3` – Parked  
    - `4` – DNS Hosting  
  - Define nameservers (for custom NS) with simple text inputs.
- On save:
  - Calls Synergy’s `updateNameServers` with:
    - `domainName`
    - `dnsConfig` (mode)
    - `nameServers` array
    - `skipDefaultARecords` flag
  - Updates the local `Domain` record (`dns_config` and `name_servers`).
  - Shows a status message including Synergy’s response status.

- When switching between DNS types:
  - The UI is designed to surface a confirmation explaining that changing DNS mode may disrupt services.

---

### 🎨 UI & theming

- Clean, modern layout with rounded “pill” controls and consistent spacing.
- **Dark mode** and light mode support:
  - Inputs and tables adopt appropriate background and border colours.
  - Cards and panels use subtle elevation and contrast.
- Buttons follow a consistent style:
  - Green for primary actions (Save, Renew, Add record, Bulk sync).
  - Red for destructive actions (Delete).
  - Neutral pills for secondary navigation and filters.

## Deploying updates

Run the bundled script from the app directory as root:

```bash
sudo bash deploy/deploy.sh
```

It pulls, installs dependencies, builds assets, migrates, rebuilds caches, hands `storage/` back to `www-data` (otherwise php-fpm cannot write logs/views and every page returns HTTP 500), restarts queue workers and reloads php-fpm. Set `WEB_USER` / `PHP_FPM_SERVICE` if yours differ.

## Deployment notes: front-end assets

All styling lives in `resources/css/app.css` and is compiled by Vite into `public/build` (gitignored). The layout no longer carries inline CSS, so **every deploy must rebuild the assets** or pages render unstyled:

```bash
npm ci
npm run build
php artisan optimize:clear
```

If Node is not available on the server, run `npm run build` on a build machine/CI and copy `public/build/` to the server. Make sure no stale `public/hot` file exists in production.

## Deployment notes: background sync (Halo / IT Glue)

Halo and IT Glue data is synced by queued jobs into local tables (`halo_clients`, `halo_assets`, `itglue_organizations`, `sync_runs`); pages read those tables and never call the vendors. Admin "Sync" / "Refresh now" buttons and `php artisan domaindash:sync-task ...` only dispatch jobs and return immediately.

Production therefore needs:

1. `QUEUE_CONNECTION=database` (or `redis`). Do **not** use `sync` (the phpunit default) - jobs would run inside the web request.
2. A supervised queue worker (see **Queue worker** below).
3. The scheduler, every minute, as `www-data` (see below). It also sends the worker heartbeat.
4. `php artisan migrate` (adds the mirror tables, `sync_runs`, sync columns and indexes) and a first "Refresh now" (or wait for the schedule) to populate the mirrors.
5. Cache store with locks (`database`/`redis`) so rate limits, overlap protection and the Halo token lock work across workers.

Tunables are in `config/integrations.php` / env (`HALO_RATE_LIMIT_PER_MINUTE`, `ITGLUE_RATE_LIMIT_PER_MINUTE`, `SYNC_QUEUED_STALE_MINUTES`, `SYNC_RUNNING_STALE_MINUTES`, `DB_QUEUE_RETRY_AFTER`, ...). Monitor `php artisan queue:failed` and the `sync_runs` table.

### Queue worker

Every "Sync"/"Refresh now" button only enqueues a job; **nothing syncs unless `queue:work` is running** on the queue the app dispatches to. The UI shows the run live (Queued, Running x of y, Succeeded / Partial / Failed / Skipped with the error text) and a warning banner "No queue worker is processing jobs ..." when the oldest waiting job is more than 2 minutes old or the per-minute heartbeat (`QueueHeartbeatJob`, sent by the scheduler) is stale. `GET /admin/sync/status` exposes the same data under `worker`.

**Timeouts (important).** Each sync job sets its own `$timeout` (`config/integrations.php` -> `sync.timeouts`: mirror jobs 240s, fan-out 120s, pushes 180s with a 120s budget) and that takes precedence over the worker flag, but run the worker with an explicit, consistent value anyway:

| Setting | Value |
|---|---|
| job `$timeout` | 120-240s (in code) |
| worker `--timeout` | 300 (larger than any job; without the flag the default is 60s, which kills long jobs) |
| `DB_QUEUE_RETRY_AFTER` | 360 (default now; **must be larger than `--timeout`**, otherwise a running job is handed to a second worker) |

**Install as a systemd service** (as root; skip if you already supervise a worker with Supervisor/systemd - just make sure it passes `--timeout=300`, the right `--queue`, and runs as `www-data`):

```bash
cd /var/www/domaindash
cp deploy/queue/domaindash-queue.service /etc/systemd/system/      # edit WorkingDirectory / --queue / --timeout first
cp deploy/queue/domaindash-schedule.service deploy/queue/domaindash-schedule.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now domaindash-queue domaindash-schedule.timer
systemctl status domaindash-queue; journalctl -u domaindash-queue -f
```

If `INTEGRATIONS_QUEUE=integrations` is set in `.env`, the worker must listen on it: `--queue=integrations,default`. A plain `queue:work` listens on `default` only, and the jobs wait forever.

Cron alternative to the timer (for `www-data`, never root): `deploy/queue/cron.example`.

**Every deploy** (workers keep the old code in memory until restarted):

```bash
php artisan migrate --force
php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart          # needs a working cache store
```

**Permissions.** The worker and scheduler run as `www-data` and only need *read* access to the code and *write* access to `storage/` (and `bootstrap/cache` / the SQLite file if used). This matches the Platform HTTPS notes below: the code tree stays root-owned and not writable by `www-data` because the certificate helper runs it as root. Never run `queue:work` or `schedule:run` as root against `storage/`: root-owned log/cache files break the web app.

**Stuck or failed syncs.** Orphaned runs are failed automatically ("Worker did not pick up the job", "Run stalled") and a fresh dispatch is never blocked by them. To reset by hand:

```bash
sudo -u www-data php artisan domaindash:sync-reset --dry-run               # what would change
sudo -u www-data php artisan domaindash:sync-reset                         # fail stale runs
sudo -u www-data php artisan domaindash:sync-reset --all --clear-locks     # fail every queued/running run, drop leftover job locks (CACHE_STORE=database)
sudo -u www-data php artisan domaindash:sync-reset --flush-failed='App\Jobs\SyncSynergyDomainsJob'   # delete old failed_jobs of one class
php artisan queue:failed ; php artisan queue:flush                         # list / delete ALL failed jobs
```

**No worker available right now?** Admins get a "Run now" button on a queued domain sync while the worker banner is showing: it pushes up to 25 selected domains inside the request (20s budget, same Halo rate limiter as the worker) and hands the rest back to the queue. It is audit-logged and needs the `sync.run` permission.

### HaloPSA and IT Glue setup: pick the asset type, map the fields

Both integrations are set up from the admin UI; no ids need to be looked up by hand. Save the connection details (base URL, credentials) first, because the pickers use the *saved* credentials.

**HaloPSA** (Settings -> HaloPSA -> *Domain asset type & field mapping*, Administrator only):

1. Click **Load asset types**. DomainDash calls `GET /api/AssetType` (cached 10 minutes; **Refresh** bypasses the cache) and lists every asset type. The type named "Domain" is pre-selected when it is unique.
2. Choose the asset type that holds domains. DomainDash calls `GET /api/AssetType/{id}?includedetails=true` and shows its fields.
3. For each DomainDash value (domain name, expiry date, name servers, WHOIS / registrant summary) choose the Halo field that receives it, or **Don't sync**. Fields are suggested by name (contains "domain", "expir", "name server"/"ns", "whois"/"registrant"); the label says whether a choice is current, suggested or manual. Required Halo fields that are left unmapped are flagged.
4. **Key fields** (Halo key field 1-3) are matched to the mapped fields automatically; adjust or leave empty per slot.
5. **Test mapping** shows, for one domain, exactly which Halo field each value would go to. It is a dry run: nothing is sent to HaloPSA.
6. Save settings. The asset type id and name, the field ids and names, and the key fields are stored in the existing `halo` setting; existing installs keep working with the old defaults (asset type looked up by the name "Domain", field ids 148/149/150/165) until a type is chosen. *Advanced: manual IDs* still lets you type ids (0 = don't sync, blank = default).

The asset type id and the mapping are part of every domain's sync hash, so changing them re-pushes all domains on the next sync (about one Halo call per domain, rate limited). Each `fields[]` entry is sent as `{id, name, value}` (name only when it was picked from the list). Halo's asset sync job lists assets filtered by the configured asset type id. When Halo rejects a push, the (sanitized, truncated) response body is shown on the failed domain and logged.

**IT Glue** (Settings -> ITGlue -> *Flexible asset type & field mapping*):

1. Click **Load flexible asset types** (`GET /flexible_asset_types?filter[enabled]=true`, paged, cached 10 minutes) and choose the type that holds domains.
2. DomainDash loads its fields (`GET /flexible_asset_types/{id}?include=flexible_asset_fields`) and suggests a trait for domain name, name servers, expiry, WHOIS and DNS by field name and kind. Only Text, Textbox and Date fields can be filled; Tag, Select, Upload and other kinds are shown greyed out. Required IT Glue fields that are not mapped are flagged (IT Glue rejects the create without them).
3. Save. The type id/name and the trait keys (`flexible_asset_traits`) are stored in the existing `itglue` setting; *Advanced* still shows the raw id and trait keys. Changing the mapping re-pushes all domains. Values previously written to a trait key that is no longer mapped stay in IT Glue; DomainDash never deletes them.

Both mapping changes are audit-logged (`integration.mapping.update`, ids and names only).

**Sync lists.** The Halo (clients, domains) and IT Glue (organisations, domains) lists in Settings have a live filter box, a sort menu (default: failed and not-synced first), status chips and select visible / all / clear. The chosen sort is remembered in the browser. Clicking **Sync Selected** shows the overall progress bar (x of y, succeeded / failed / unchanged) and a live status per row (Queued, Syncing, Synced, Failed with the reason); per-row results come from `GET /admin/sync/runs/{run}/items` (needs `sync.run`), and the list re-sorts itself when the run finishes.

## Security configuration

Settings live in `config/domaindash.php` and `.env` (see `.env.example`).

- **Registration** is OFF by default (`ALLOW_REGISTRATION=false`). Users are created by administrators (who are marked email-verified) or the API. `User` implements `MustVerifyEmail`.
- **MFA enforcement**: roles in `MFA_REQUIRED_ROLES` (default `Administrator,Technician`) must enrol 2FA; so must any user whose MFA preference is `enforced`. Until they do, every route except `/me/mfa/*`, password confirmation and logout is blocked: the dashboard URL shows only a setup page, everything else redirects there (403 JSON for API-style and Livewire requests). Existing servers that copied `.env.example` with `MFA_REQUIRED_ROLES=Administrator` must update `.env` to include Technician. `mfa_preference = disabled` is not accepted for those roles.
- **Break-glass** (locked-out administrator): run `php artisan domaindash:mfa-reset admin@example.com` on the server, or set `MFA_ENFORCE=false` in `.env` + `php artisan config:clear` temporarily, then re-enable.
- **Settings encryption**: `settings.value` is stored encrypted with `APP_KEY` (`enc:v1:` prefix); `php artisan migrate` encrypts existing rows. Keep `APP_KEY` out of backups and never rotate it without re-saving settings. Rotate all vendor credentials after upgrading, as the old plaintext may exist in backups and `audit_logs`.
- **API keys** are now `<prefix>.<secret>`; only the prefix and a SHA-256 hash are stored. Existing (bare 48-hex) keys keep working and are upgraded in place on first use. Keys with no scopes have no access; `users.write` cannot assign Administrator (needs the explicit `users.admin` scope); EPP codes need `domains.auth-code`.
- **Security headers** (`SECURITY_HEADERS`, `CSP_ENFORCE`, `CSP_REPORT_URI`): CSP is report-only until `CSP_ENFORCE=true`. The policy still allows `'unsafe-inline'`/`'unsafe-eval'` (Alpine and Livewire need them), so enforcing it only blocks foreign script/frame/form/connect origins and gives limited XSS protection until a nonce rollout; page output is escaped independently of the CSP. Recommended path: leave report-only, set `CSP_REPORT_URI`, check the reports for a few weeks, then set `CSP_ENFORCE=true`.
- **APP_URL and Host header**: set `APP_URL` to the exact public https URL (e.g. `https://dash.example.com`). Only that host is accepted in the `Host` header (`bootstrap/app.php` trusted hosts), and all generated links (password reset, invitations) are built from `APP_URL`, never from the request. `deploy/https/nginx-domaindash.conf.example` also contains a catch-all server that drops requests for unknown host names.
- **Backups** (`php artisan domaindash:backup-run`, scheduled 03:00): the database (MySQL/MariaDB via `mysqldump`, or the SQLite file) is zipped with AES-256 encryption and uploaded over SFTP. In Settings -> Backups you must set the **SFTP host fingerprint** (get it with `ssh-keyscan -t ed25519 HOST | ssh-keygen -lf -`, paste `SHA256:...`) and a **backup encryption password** (min 12 characters, keep it safe: without it a backup cannot be opened). A backup is refused if either is missing. Local temporary copies are deleted after each run, the result is written to the audit log, and the Settings tile shows the last successful backup. Backups do not contain `.env`/`APP_KEY`: store `APP_KEY` separately, otherwise encrypted settings in a restored database cannot be read.
- **Audit retention**: security events (`auth.*`, `mfa.*`, `impersonation.*`, `domain.auth-code-read`, `hosting.password-reveal`, `apikey.*`, `settings.*`, `user.*`, `backup.*`, `saml.*`) are always kept at least 365 days, whatever the activity retention is. `AUDIT_SYSLOG=true` also sends audit events to the server syslog (tag `domaindash-audit`) so a copy exists outside the database.
- **Settings validation**: SMTP and backup hosts must be public hostnames/IPs (private ranges are rejected; set `DOMAINDASH_ALLOW_PRIVATE_HOSTS=true` only if you deliberately use an internal mail relay or backup server). `synergy.wsdl_path` must be a synergywholesale.com URL or a file inside the app.
- **Permissions**: `deploy/deploy.sh` makes `storage/` and `database/` unreadable by other users, and the private disk writes files `0600` / directories `0700` and is never served over HTTP.
- **Mail**: in production, password-reset / invitation / verification mail is refused (and an alert logged) while `MAIL_MAILER=log`, so reset links never end up in `storage/logs`. Configure SMTP in Settings -> Email.
- Run `composer update` + `composer audit` and `npm audit --omit=dev` as part of CI.

## Platform HTTPS

Serve the DomainDash dashboard itself over HTTPS (Settings -> **Platform HTTPS**, admin only, MFA enforced like the rest of the admin area). Two sources are supported: **Let's Encrypt via DNS-01** (a `_acme-challenge` TXT record is created in Synergy; **port 80 is not needed**; wildcard `*.example.com` works) and **uploaded certificates** (PEM or PFX). Target: Ubuntu + systemd, nginx -> php-fpm (`www-data`), certbot installed with pip (`/opt/certbot`, symlinked to `/usr/bin/certbot`).

### How it works

```
 browser ──► DomainDash web (www-data) ───────────────┐  writes requests, reads status
             - validates input / uploads (openssl_*)   │  (never runs certbot/sudo/shell,
             - Force-HTTPS middleware, HSTS            │   never writes nginx or cert files)
                                                       ▼
                                          DB: platform_certificate_requests
                                              platform_certificates, settings(heartbeat)
                                                       ▲
 systemd timer (every minute) ──► root: php artisan domaindash:certctl tick
 systemd timer (03:00/15:00 +RandomizedDelay) ──► ... certctl renew  (certbot renew --quiet)
        │  re-validates EVERYTHING from the DB (hostname, email, PEMs); fixed allowlist: letsencrypt | upload | renew
        │  Symfony Process with array args, fixed absolute paths, no shell
        ├─► certbot certonly --manual --preferred-challenges dns
        │       --manual-auth-hook    /usr/local/lib/domaindash/acme-dns-auth    ─► artisan domaindash:acme-dns auth
        │       --manual-cleanup-hook /usr/local/lib/domaindash/acme-dns-cleanup ─► artisan domaindash:acme-dns cleanup
        │       --deploy-hook         /usr/local/lib/domaindash/certbot-deploy-hook (nginx -t && systemctl reload nginx)
        │           hooks: validate CERTBOT_DOMAIN / CERTBOT_VALIDATION, find the Synergy zone, add/remove the TXT
        │           (TTL 300), poll public DNS until visible (default 180s, every 10s), remove only its own record
        └─► writes /etc/nginx/snippets/domaindash-ssl.conf ─► nginx -t ─► (fail: restore previous) ─► systemctl reload nginx
              uploads: /etc/ssl/domaindash/<host>/{fullchain,privkey}.pem   (key 0600 root)
              Let's Encrypt: /etc/letsencrypt/live/<name>/ (certbot-owned; keys never enter the DB)
```

**Why an artisan command run as root (and not a bash script).** Let's Encrypt DNS-01 needs the Synergy API credentials, which live encrypted in the `settings` table and can only be read with `APP_KEY` and the app's Synergy client. A bash helper would need its own copy of those secrets or the key. The artisan helper reuses the app code, but because it runs as root it **never trusts the database**: hostnames/emails are re-validated with strict RFC 1123/allowlist regexes, uploaded PEMs are re-validated (key match, expiry, SAN, chain order), only three operations exist, every external program is a fixed absolute path run with an argument array, and file targets are fixed directories plus a validated hostname. Tradeoff: **root executes the application's PHP code**, so the web user must not be able to modify that code; `install.sh` refuses to install unless `www-data` has no write access to `artisan`, `app/`, `bootstrap/`, `config/`, `routes/`, `vendor/`, `database/` and `.env` (see step 1). Uploaded private keys are stored in the DB encrypted (`Crypt`, `APP_KEY`), wiped from the request payload after installation, and installed root-only (0600).

### Install (exact steps)

1. **Lock down the code** (the helper runs it as root). As root, once and after each deploy that changes files:
   ```bash
   cd /var/www/DomainDash
   chown -R root:root .                      # or your deploy user; just NOT www-data
   chown -R www-data:www-data storage        # the only writable tree
   php artisan package:discover && php artisan config:cache && php artisan route:cache   # bootstrap/cache is pre-built, read-only for www-data
   chgrp www-data .env && chmod 640 .env
   ```
   If you use SQLite, the database file and its directory must stay writable by `www-data` *and* root (`chown www-data:www-data database/ database/database.sqlite`).
2. `php artisan migrate --force`
3. Check `certbot --version` works as root and `/usr/bin/certbot` exists (`ln -s /opt/certbot/bin/certbot /usr/bin/certbot`).
4. `sudo bash deploy/https/install.sh` (idempotent; options `--app-dir`, `--php`, `--web-user`, `--no-start`). It installs wrappers into `/usr/local/lib/domaindash/`, creates `/etc/ssl/domaindash` and an empty `/etc/nginx/snippets/domaindash-ssl.conf`, installs `domaindash-certctl.{service,timer}` (every minute) and `domaindash-certbot-renew.{service,timer}` (03:00 and 15:00, `RandomizedDelaySec=3600`, `Persistent=true`) and enables both timers. Pip-installed certbot has no renewal timer of its own; this is it.
5. In the UI the "helper not installed or not running" warning disappears within a minute. Request a certificate (tick **staging** first to test; staging certificates are not browser-trusted) or upload one.
6. Add the HTTPS server block: copy `deploy/https/nginx-domaindash.conf.example` (443 only; includes the managed snippet), or add `include /etc/nginx/snippets/domaindash-ssl.conf;` to your existing 443 block. Then `nginx -t && systemctl reload nginx`. The helper keeps the snippet current on every issue/renewal/upload.
7. Open `https://<host>/` and, once it loads cleanly, enable **Force HTTPS** in the UI (only possible with a valid active certificate).

Environment (`.env`): `TRUSTED_PROXIES` (empty = trust nobody; `*` or a comma list of IPs/CIDRs when behind Cloudflare/a load balancer so `request()->isSecure()` works; implemented in `config/trustedproxy.php`), `PLATFORM_PUBLIC_IP` (optional, informational), `HTTPS_DNS_PROPAGATION_TIMEOUT` (180), `HTTPS_DNS_POLL_INTERVAL` (10), `HTTPS_DNS_SETTLE_SECONDS` (15). Directly behind nginx/php-fpm, `include fastcgi_params;` already passes `HTTPS` so no proxy setting is needed.

Wildcard certificates: because validation is DNS-01, `*.example.com` can be requested (enter it as the hostname). The wildcard does not cover `example.com` itself. DNS for the domain must be managed in Synergy (the domain must exist in DomainDash/Synergy); otherwise use **Upload certificate**.

### Migrating from `certbot --nginx`

If you already ran `certbot --nginx -d dash.example.com`, certbot edited your server block and its renewal config uses the nginx plugin (which needs port 80 - closed here - so those renewals will fail). To migrate:
1. Back up: `cp -a /etc/nginx /root/nginx.bak; cp -a /etc/letsencrypt /root/letsencrypt.bak`.
2. Remove the certbot-added `ssl_certificate*`, `listen 443 ssl`, `include .../options-ssl-nginx.conf` and `ssl_dhparam` lines from the server block and use the example block instead (`include` of the managed snippet).
3. `certbot delete --cert-name dash.example.com` (this drops the old nginx-plugin renewal), then request the certificate from the UI so it is re-issued with DNS-01 and the DomainDash hooks.
4. `nginx -t && systemctl reload nginx`. If you cannot delete the lineage yet, upload/keep it and re-issue later; `certbot renew` for the old lineage will keep failing until it is deleted.

### Troubleshooting

| Symptom | Check |
|---|---|
| "helper not installed or not running" | `systemctl list-timers 'domaindash-*'`, `journalctl -u domaindash-certctl -n 50`. A common cause is a failing `artisan` boot as root (wrong `--php`, unreadable `.env`). |
| Request stuck "pending" | Helper not running (above), or a previous request still running (`journalctl -u domaindash-certctl`). Running requests older than 45 minutes are failed automatically. |
| "DNS for X is not managed in Synergy" | The domain (or a parent) must exist in the Synergy account/DomainDash domains list. Otherwise upload a certificate. |
| "TXT record did not become visible" | The domain's nameservers must be Synergy's (`dig NS example.com`). Raise `HTTPS_DNS_PROPAGATION_TIMEOUT`. The record is removed automatically on timeout. |
| "Synergy credentials check failed" | Settings -> Synergy reseller ID / API key; the Synergy API key may be IP-restricted: allow this server's IP. |
| `nginx -t` failed in the log | The helper restored the previous snippet; run `nginx -t` yourself and fix the conflicting directive (e.g. duplicate `ssl_protocols`/`ssl_session_cache` zone names in other server blocks). |
| Browser warns "not trusted" | You used staging. Request again with staging unticked. |
| Locked out after Force HTTPS | Fix HTTPS first; to disable: `php artisan tinker --execute="App\Services\Https\PlatformHttps::setForce(false);"`. |
| Renewal failed alert | Settings -> Platform HTTPS -> request history (renew entries), `journalctl -u domaindash-certbot-renew`, `certbot certificates`, `tail /var/log/letsencrypt/letsencrypt.log`. |

Manual run for debugging (as root): `php artisan domaindash:certctl tick` / `renew`. Daily expiry alerts (30/14/7/1 days and failed renewals) come from `domaindash:https-check` (scheduler): audit log, in-app notification for every Administrator, and the email templates "Platform HTTPS Certificate Expiring" / "Renewal Failed" (set an admin recipient under Email Templates to enable mail).

### Rollback / uninstall

* Roll back a certificate: re-upload the previous one, or `certbot certificates` / `certbot delete`. To stop using the managed snippet: remove the `include` line from the server block, `nginx -t && systemctl reload nginx`.
* Disable the automation: `systemctl disable --now domaindash-certctl.timer domaindash-certbot-renew.timer`.
* Full removal: `sudo bash deploy/https/install.sh --uninstall` (removes wrappers and units; leaves the snippet, `/etc/ssl/domaindash` and `/etc/letsencrypt` for you to delete deliberately). Drop the tables with `php artisan migrate:rollback --path=database/migrations/2026_10_20_000001_create_platform_https_tables.php`.

## Vocus sandbox

Settings → Vocus Wholesale → **Environment: Sandbox**. The sandbox shares the production hostname but is only reachable at an unadvertised IP (Vocus Sandbox guide 2.1, default `203.23.116.113`, override with the *Sandbox IP address* field or `VOCUS_SANDBOX_IP`). With "Connect to this IP in sandbox mode" ticked, DomainDash pins that IP for the login and SOAP calls itself, so **no hosts-file entry is needed** — remove any `wsm.webservice.m2.com.au` line from `/etc/hosts` when switching back to production, otherwise production traffic would still go to the sandbox. Upload the sandbox's CA certificate (PEM) in the same section; TLS verification stays on. Switching environment starts a new Vocus session.

## Vocus NBN ordering (Settings → Vocus Wholesale → Ordering options)

Run `php artisan migrate` after updating: it adds `vocus_plans` and the plan / cost / sell snapshot columns on `internet_services`.

- **PPPoE realms**: the order and transfer forms ask only for the unique part of the username (2 to 22 characters, `A-z 0-9 _`) plus a realm chosen from this list. DomainDash sends `Username` = `username@realm` and `Realm`. A blank username omits `Username`, so Vocus generates it. The password is required on Reseller-Connect (2 to 30 characters, `A-z 0-9 - .`). Network-Connect / NCL2 orders send only the realm (no username, password, authentication type or IP type).
- **Service scopes**: tick which of RESELLER-CONNECT / NETWORK-CONNECT the forms offer (one enabled scope is pre-selected and read-only).
- **Plans**: Plan ID (the Vocus PlanID), name, speed tier, scopes, cost and sell price (AUD per month) and an active flag. The chosen plan's cost and sell price are copied onto the service at order time. Admins see cost, sell and margin; clients only see the sell price (dashboard → Internet Services).
  - **Plans per technology and mapping** (run `php artisan migrate`: adds `technology` and `mapping` to `vocus_plans`; `plan_id` stays unique). The Vocus bundle sheet lists one PlanID per technology, speed and mapping (Tagged or Default Map, `-DM` ids), so DomainDash keeps **one row per Plan ID**, with its own cost and sell price (prices can differ by technology). Each plan has an optional **Technology** (FTTP, FTTB, FTTN, FTTC, HFC, WIRELESS) and **Mapping** (TAGGED, DEFAULT_MAP); both blank = offered everywhere, as before. The Plans modal has a search box, technology and mapping filters and sortable columns.
  - **Account mapping** (Settings, Vocus, Plans, "Account mapping", setting `vocus.plan_mapping`, default Default Map). The order and transfer forms offer only active plans whose mapping matches (or is blank), whose technology matches the qualified technology (QUALIFY `FIBRE` is FTTP; Fixed Wireless / WIRELESS / FW is WIRELESS; plans with no technology are shown for all), and that pass the existing scope and line-capability filters. Without a qualification token (manual order) the technology is not filtered but the mapping still is. `orderStore` and `transferStore` enforce the same rules before any Vocus call.
  - **Import Vocus plan sheet** (Plans modal): upload the `.xlsx` (or `.csv`) sheet, up to 2 MB. No composer package is used: the workbook is read with `ZipArchive` + `SimpleXML` (needs the PHP `zip` extension; a clear error is shown without it, or upload `.csv`). Only the left table (PlanID, ProductID, Technology, Mapping, LineSpeed) is read; the header row is found by `FIBRE PlanID` / `Technology`. A preview shows new / updated / unchanged counts and sample rows; **Apply import** then upserts by `plan_id`. Existing plans only get technology, mapping, speed tier and Mbps updated (name, prices, scopes and active flag are never overwritten, and an unknown named tier never wipes speeds). New plans are created **inactive with cost and sell $0.00** and the name `{Technology} {LineSpeed} ({Mapping})`, so you price and activate them. The "Only import ... rows (the account mapping)" box is ticked by default when the account mapping is Default Map. The import is audit logged with counts only.
  - **Named tier speeds**: LineSpeed values such as Home Fast are not "down/up". Defaults (config `services.vocus.named_tiers`, editable in Plans modal, "Named tier speeds"): Home Fast 500/50, Home Super Fast 750/50, Home Ultra Fast 1000/100, Home Hyper Fast 2000/200, Fixed Wireless Plus unknown. They follow NBN's published tiers and can be edited; a plan with unknown speed is never hidden by line capability.
  - **Rules from the sheet notes**: Home * tier plans only support the STANDARD service level (other levels are hidden in the form when such a plan is selected and refused server-side). For HFC 250/100, Home Ultra Fast and Home Hyper Fast, and FTTP Home Hyper Fast and 2000/500 the form shows "Availability depends on the SQ result" (the line-capability filter applies when Vocus returns speeds).
  - **NBNCPEPlanID** options come from the sheet, grouped by technology (`VocusOptions::NBN_CPE_PLANS`): FTTC `FTTCNCDNBNPROFINSTALL`, `FTTCNCDRC`; HFC `HFCNTDNBNPROFINSTALL`, `HFCNTDNBNPROFINSTALL-MISSING`, `HFCNTDWHS`, `HFCNTDWHS-MISSING`; FTTP `FTTPNTDNBNPROFINSTALL-V3-1P`, `-V3-1P-R`, `-V4-4P`, `-V4-4P-R` (all FTTP NTD installs are professional install only; `-R` replacement options need the NTD to replace). The order form dropdown lists only the qualified technology's options. Options that ship equipment (`HFCNTDWHS*`, `FTTCNCDRC`) need a CPEDirectoryID shipping address, are shown disabled and are refused server-side. The sheet's router CPEPlanIDs are kept in config `services.vocus.router_cpe_plans` for future modem shipping and are not used.
  - Tests: `php artisan test --filter=VocusPlanSheetTest`.
- The ServiceID is generated by DomainDash (`DD-<12 LOC digits>-<4 chars>`): the WSM Create call requires a client-supplied unique id. Technology comes from the qualify result; AVC / CVC are only on transfers. The qualify page can also start from an NBN LOC ID (`LOC` + 12 digits).
- Verified against the Vocus Product Catalogues (see `docs/review/14-vocus-catalogue-verification.md`): the forms send `AuthType` (PPPoE / IPoE), `IPAssignment` (CGNAT / STATIC / DYNAMIC), `Reference` (customer reference), `CentralSplitter` and `VoicebandContinuity` / `CarrierID` (FTTB/FTTN new orders), `OrderType` (NEW, TRANSFER or OUTSTANDING), `CVCID` / `CTAG` / `RSPSTAG` / `RSPCTAG` (Layer 2) and an optional `NBNCPEPlanID` install option. Run `php artisan migrate` (adds `auth_type`, `ip_assignment`, `ip_address`, `reference`, `central_splitter`; widens `order_type`).
- **Technology-aware qualify and order (lines and ports)**: run `php artisan migrate` (adds `ntd_id`, `uni_port`, `service_class` to `internet_services`). The qualify result is normalised (`App\Services\Vocus\VocusQualification`): every `CopperPairRecord`, `NTDRecord` and `NBNPortRecord` CDATA param (single, repeated, several in one CDATA, or already decoded) becomes `copper_pairs[]` and `ntds[]` with `ports[]` grouped by `NTDID`. Technology group: **COPPER** = FTTN, FTTB and FTTC; **NTD** = FTTP, HFC and Fixed Wireless; anything else (for example Satellite) has no line / port to choose and is ordered without one. The records Vocus returns decide the group, the `ServiceType` is the fallback.
  - Qualify page lists the lines (copper pair, status, NBN service status, class, speed range, network co-exist and extra-charge warnings) or each NTD with its ports (DATA / VOICE, Free / In use). The service class meaning is Vocus's table (FIBRE catalogue appendix 6.2).
  - The order and transfer forms choose the line through a **Select line / port** modal: free items are selectable, in-use ports are shown disabled, a single option is preselected and several are never guessed. **Order a new line** (new orders only) omits the line / port param. The choice is shown read-only with a **Change** button. The qualify result is cached for `VOCUS_QUALIFICATION_TTL` minutes (default 60) under a token carried to the form; the server re-checks the submitted pair / port against it. Opened without it, the form falls back to the manual Copper Pair ID field.
  - **Line / port params (FIBRE catalogue 4.1)**: copper sends `CopperPairID` (an id, or `NEW` for a new pair); FTTB / FTTN new orders also send `VoicebandContinuity`, `CentralSplitter` and `CarrierID`. FTTP and Wireless **new** orders send no port (Vocus auto-selects a free UNI-D port; FTTP may send `NTDID`). A transfer sends `DataPortNumber` (1 to 4; override name with `VOCUS_TRANSFER_PORT_PARAM`) and may pick an in-use port; HFC sends `NTDID` (or `NEW`). The technology sent on Create is FTTP / WIRELESS / FTTB / FTTN / HFC / FTTC (QUALIFY's FIBRE becomes FTTP). Appointments are booked by NBN on the first available date; Create has no appointment fields. Unverified: NTD speeds as a plan cap (`VOCUS_NTD_SPEED_IS_CAP=false` disables it).
- Tests: `php artisan test --filter=VocusLineSelectionTest`, `--filter=VocusOrderingUxTest` and `--filter=VocusInternetTest`.

## HaloPSA billing of internet services (Settings → HaloPSA → Billing, Settings → Vocus → Plans)

Run `php artisan migrate` after updating: it adds `vocus_plans.halo_item_id / halo_item_name`, the `halo_*` billing columns on `internet_services` and `clients.halo_recurring_invoice_id / _name`. Field names and endpoints are documented in `docs/review/16-halo-subscriptions-swagger-facts.md` (from the tenant swagger); domain billing is only planned in `docs/review/17-halo-domain-billing-plan.md`.

- **Plan to item**: Settings → Vocus → Plans, column "Halo item", **Link** opens a search dialog (debounced as you type, results cached 60 s: name, code, price, recurring flag). One item can serve many plans and technologies. **Unlink item** clears it. The linked item name is shown in the plans list.
- **Billing model**: each Vocus service becomes a **subscription** on the client in HaloPSA (`/api/SoftwareLicence`: plan item, sell price, cost, quantity 1, start date = order date, notes carry the Vocus service id, plan, username, address) and a **line on the client's recurring invoice** that has the subscription attached (`/api/RecurringInvoice/Lines`, `updatelines`).
- **Settings → HaloPSA → Billing** (the only billing settings; the old Billing tile and `/admin/settings/halo-billing` now lead here) has three sections. **Agreements**: how a service finds its HaloPSA agreement, by Contract Type + Agreement Subtype (both pickers filled live from Halo, cached with Refresh) + an optional Agreement Reference pattern (`*`/`?` wildcards, tokens such as `{plan}`, `{client_code}`, `{service_id}`). One agreement per connection is expected: with several matches the one whose reference names the service is used, otherwise the sync error lists the candidates. Order: invoice chosen on the service page (Internet service → HaloPSA billing card) → agreement rule → invoice chosen on the client (fallback). Halo does not return subtype names, so enter the Halo lookup id of the subtype list to see them (otherwise "Subtype #id" with an example agreement). **Recurring invoice lines**: description and long description templates with DomainDash placeholders and Halo's `$PERIODSTARTDATE` / `$PERIODENDDATE` passed through untouched, plus a Preview of the text and the chosen agreement for one service (writes nothing). **Subscriptions**: sync subscriptions (default off). DomainDash never creates agreements or recurring invoices. Migration: `halo_override_invoice_*`, `halo_contract_*` on `internet_services`. Tests: `--filter=HaloAgreementBillingTest`.
- **When it runs**: automatically (queued, debounced 30 s per service) when a service with a client is ordered, a client is assigned, or the plan, price or status changes, and when a plan's item or a client's invoice changes. **Sync to Halo** on the service page and **Sync to HaloPSA** on the Internet Services list run it now with live progress (needs the `sync.run` permission and a queue worker). Idempotent: ids are stored on the service (`halo_subscription_id`, `halo_recurring_line_id`), an unchanged payload is skipped (`halo_sync_hash`), a cancelled service gets an end date / is deactivated and is never deleted. A service still being provisioned is not billed until Vocus reports it ACTIVE. Failures are stored per service (`halo_sync_error`) and shown as a chip on the service page and list.
- Optional env: `HALO_ITEM_SEARCH_TTL`, `HALO_SUBSCRIPTION_DEBOUNCE`, `HALO_RECURRING_QUANTITY_TYPE` (tenant-specific id that makes Halo take a line's quantity from its subscription).
- Tests: `php artisan test --filter=HaloBillingTest`.
