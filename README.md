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

**HaloPSA custom fields.** Settings -> HaloPSA -> *Asset custom field IDs* must match the Domain asset type's custom field IDs in your tenant (defaults 148/149/150/165). When Halo rejects a push, the (sanitized, truncated) response body is shown on the failed domain and logged.

## Security configuration

Settings live in `config/domaindash.php` and `.env` (see `.env.example`).

- **Registration** is OFF by default (`ALLOW_REGISTRATION=false`). Users are created by administrators (who are marked email-verified) or the API. `User` implements `MustVerifyEmail`.
- **MFA enforcement**: roles in `MFA_REQUIRED_ROLES` (default `Administrator`) must enrol 2FA. Until they do, every route except the dashboard (setup prompt), `/me/mfa/*`, Fortify two-factor endpoints and logout redirects to the dashboard (403 JSON for API-style requests). `mfa_preference = disabled` is not accepted for those roles.
- **Break-glass** (locked-out administrator): run `php artisan domaindash:mfa-reset admin@example.com` on the server, or set `MFA_ENFORCE=false` in `.env` + `php artisan config:clear` temporarily, then re-enable.
- **Settings encryption**: `settings.value` is stored encrypted with `APP_KEY` (`enc:v1:` prefix); `php artisan migrate` encrypts existing rows. Keep `APP_KEY` out of backups and never rotate it without re-saving settings. Rotate all vendor credentials after upgrading, as the old plaintext may exist in backups and `audit_logs`.
- **API keys** are now `<prefix>.<secret>`; only the prefix and a SHA-256 hash are stored. Existing (bare 48-hex) keys keep working and are upgraded in place on first use. Keys with no scopes have no access; `users.write` cannot assign Administrator (needs the explicit `users.admin` scope); EPP codes need `domains.auth-code`.
- **Security headers** (`SECURITY_HEADERS`, `CSP_ENFORCE`, `CSP_REPORT_URI`): CSP is report-only until `CSP_ENFORCE=true`.
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
