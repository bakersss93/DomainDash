---
title: API keys and scopes
summary: Create keys for the REST API, choose the least access they need, restrict them by IP and rotate them safely.
category: security
order: 30
audience: integration
related: [audit-logs-and-retention, users-roles-permissions-mfa]
---

API keys let automation (a monitoring tool, a script, an automation platform) call the {company} REST API without a person signing in.

**Where:** **Admin > API Keys** (Administrators only).

## Create a key

1. Select **New API key**.
2. **Name**: what the key is for, for example `Monitoring integration`.
3. **Allowed IPs (optional)**: comma-separated addresses or ranges, for example `1.2.3.4, 10.0.0.0/8`. Blank allows any address. Restricting is strongly recommended.
4. **Rate limit per hour**: requests per hour for this key (default 1000, 1 to 100000).
5. **Scopes**: tick only what the integration needs. A key must have **at least one scope**; the server rejects a key with none.
6. Select the create button. The **API key ready** window shows the key **once**. Select the copy button and store it in your password manager.

> **Warning:** Only a prefix and a hash are stored, so a lost key cannot be shown again. Use **Regenerate** to issue a new one.

New keys **expire after 365 days** by default (the server setting `API_KEY_DEFAULT_EXPIRY_DAYS`; `0` means never). An expired key answers "API key expired".

## Use a key

Send it in the `X-API-Key` header:

```powershell
Invoke-RestMethod -Uri "{app_url}/api/v1/domains" -Headers @{ "X-API-Key" = "<your key>" }
```

The OpenAPI description of every endpoint is on the same page: **Download OpenAPI (YAML)** or **Download OpenAPI (JSON)**.

Responses you may see: `401` missing, invalid or expired key; `403` IP not allowed or scope denied; `429` rate limit exceeded or too many failed attempts from your IP.

## Scopes

| Scope | Allows |
|---|---|
| `domains.read` | View domains and expiry dates |
| `domains.auth-code` | Read domain EPP / transfer auth codes (a transfer-capable secret) |
| `domains.write` | Sync from Synergy, renew, transfer and assign domains to clients |
| `dns.read` / `dns.write` | View DNS records / add, update and delete records and change nameservers or DNS mode |
| `clients.read` / `clients.write` | View / create, update and delete clients |
| `hosting.read` / `hosting.write` | View hosting services and usage / trigger hosting sync |
| `ssl.read` / `ssl.write` | View SSL certificates / trigger SSL sync |
| `internet.read` / `internet.write` | View internet services / trigger Vocus service sync |
| `tickets.read` / `tickets.write` | View HaloPSA tickets and replies / create tickets, reply and close |
| `pricing.read` | View the domain pricing catalogue |
| `users.read` / `users.write` | View users / create, update and delete **Customer** users |
| `users.admin` | **Admin-equivalent**: also assign staff roles, change user emails and modify or delete Administrator users |
| `audit.read` | View the audit log |

The sensitive scopes (`users.read`, `users.write`, `users.admin`, `audit.read`, `domains.auth-code`) are never satisfied by older broad "read" or "write" scopes.

> **Warning:** Treat `users.admin` and `domains.auth-code` like an Administrator password. Give them to as few keys as possible, with an IP restriction.

## Manage keys

The list shows **Name**, **Status**, **Allowed IPs**, **Rate limit**, **Scopes** and **Created**. The action icons are:

- **Edit**: change allowed IPs, rate limit and scopes.
- **Regenerate key**: issues a new key and invalidates the old one immediately. Update the integration straight afterwards.
- **Deactivate key** / **Reactivate key**: switch a key off and on without deleting it.
- **Delete key**: permanent.

Key events are security events: creation, changes and use are recorded in **Admin > Audit Logs**, and requests with a key appear on the **Integration Inbound** tab. See [Audit logs](/support/help/security/audit-logs-and-retention).

## Good practice

- One key per integration so you can revoke one without affecting others.
- Least scopes, restricted IPs, short expiry.
- Rotate keys when someone who knew them leaves.
