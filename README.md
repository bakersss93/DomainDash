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

## Deployment notes: background sync (Halo / IT Glue)

Halo and IT Glue data is synced by queued jobs into local tables (`halo_clients`, `halo_assets`, `itglue_organizations`, `sync_runs`); pages read those tables and never call the vendors. Admin "Sync" / "Refresh now" buttons and `php artisan domaindash:sync-task ...` only dispatch jobs and return immediately.

Production therefore needs:

1. `QUEUE_CONNECTION=database` (or `redis`). Do **not** use `sync` (the phpunit default) - jobs would run inside the web request.
2. A queue worker, supervised (Supervisor/systemd): `php artisan queue:work --tries=5 --max-time=3600` (add `--queue=integrations,default` and set `INTEGRATIONS_QUEUE=integrations` to isolate sync traffic).
3. The scheduler cron: `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`.
4. `php artisan migrate` (adds the mirror tables, `sync_runs`, sync columns and indexes) and a first "Refresh now" (or wait for the schedule) to populate the mirrors.
5. Cache store with locks (`database`/`redis`) so job uniqueness, rate limits and the Halo token lock work across workers.

Tunables are in `config/integrations.php` / env (`HALO_RATE_LIMIT_PER_MINUTE`, `ITGLUE_RATE_LIMIT_PER_MINUTE`, `INTEGRATIONS_MAX_ATTEMPTS`, ...). Monitor `php artisan queue:failed` and the `sync_runs` table.

## Security configuration

Settings live in `config/domaindash.php` and `.env` (see `.env.example`).

- **Registration** is OFF by default (`ALLOW_REGISTRATION=false`). Users are created by administrators (who are marked email-verified) or the API. `User` implements `MustVerifyEmail`.
- **MFA enforcement**: roles in `MFA_REQUIRED_ROLES` (default `Administrator`) must enrol 2FA. Until they do, every route except the dashboard (setup prompt), `/me/mfa/*`, Fortify two-factor endpoints and logout redirects to the dashboard (403 JSON for API-style requests). `mfa_preference = disabled` is not accepted for those roles.
- **Break-glass** (locked-out administrator): run `php artisan domaindash:mfa-reset admin@example.com` on the server, or set `MFA_ENFORCE=false` in `.env` + `php artisan config:clear` temporarily, then re-enable.
- **Settings encryption**: `settings.value` is stored encrypted with `APP_KEY` (`enc:v1:` prefix); `php artisan migrate` encrypts existing rows. Keep `APP_KEY` out of backups and never rotate it without re-saving settings. Rotate all vendor credentials after upgrading, as the old plaintext may exist in backups and `audit_logs`.
- **API keys** are now `<prefix>.<secret>`; only the prefix and a SHA-256 hash are stored. Existing (bare 48-hex) keys keep working and are upgraded in place on first use. Keys with no scopes have no access; `users.write` cannot assign Administrator (needs the explicit `users.admin` scope); EPP codes need `domains.auth-code`.
- **Security headers** (`SECURITY_HEADERS`, `CSP_ENFORCE`, `CSP_REPORT_URI`): CSP is report-only until `CSP_ENFORCE=true`.
- Run `composer update` + `composer audit` and `npm audit --omit=dev` as part of CI.
