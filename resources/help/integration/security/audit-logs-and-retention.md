---
title: Audit logs and retention
summary: Find who changed what, inspect integration traffic and job runs, and set how long each log is kept.
category: security
order: 40
audience: integration
related: [api-keys-and-scopes, sync-scheduler, vocus-sandbox-and-certification]
---

**Where:** **Admin > Audit Logs** (Administrators only). The page subtitle says it all: who changed what, what {company} exchanged with your integrations, and how background jobs ran.

## The tabs

| Tab | What it shows |
|---|---|
| **Activity** | What people changed: who did what, with old and new values. Failures are always shown. A **Show system activity** toggle adds automatic actions. Filter by module and by Succeeded or Failed. |
| **Integration Outbound** | Requests {company} sent to HaloPSA, IT Glue, Synergy Wholesale, Vocus and IP2WHOIS. Repeated identical requests are grouped. |
| **Integration Inbound** | API requests received with an API key. |
| **Integration Errors** | Failed integration requests and records whose last sync failed. |
| **Jobs & Schedules** | Background sync runs, scheduled and manual, with item counts and the status Queued, Running, Succeeded, Failed or partial. |
| **Vocus transactions** (link) | Every Vocus call grouped into journeys, used for [Vocus certification](/support/help/integrations/vocus-sandbox-and-certification). |

Use the **Find** box, filters and paging, the refresh button, and open a row for details. In the detail window you can **Export to JSON**, and **Delete** where deletion is allowed (the activity trail is only removed by retention).

> **Note:** Secrets are never stored, whatever the logging level. Customer email and phone values inside stored request bodies are masked unless you turn that off.

## Logging settings (on the same page)

**Logging settings** control what is recorded and for how long:

| Setting | Meaning |
|---|---|
| **Keep activity (days)** | Retention of the activity trail (default 90). |
| **Keep outbound requests (days)** / **Keep inbound requests (days)** | Retention of integration traffic (default 30 each). |
| **Keep job history (days)** | Retention of sync runs (default 90). |
| **Keep security events (days, minimum 365)** | Retention of security events. Cannot be set below 365. |
| **Outbound request logging** | **Errors only** (default, keeps the table small) or **All requests** (for troubleshooting). Vocus calls are always recorded. |
| **Inbound request logging** | **All requests** (default) or **Errors only**. |
| **Mask customer e-mail and phone values in stored request bodies** | On by default. |
| **Apply retention now** | Tick and save to prune immediately instead of waiting for the nightly job. |

Select **Save settings**. The page also shows approximately how large the audit tables and the log file are.

## Retention rules

- A nightly job prunes entries older than the retention you set.
- **Security events** (sign-ins and sign-in failures, MFA, impersonation, secret reveals such as EPP code or hosting password, API key events, settings changes, user changes, backups and SSO) are **always kept at least 365 days**, whatever the activity retention is.
- The same retention for the activity trail also appears under **Admin > Settings > MFA and audit > Audit log** (**Keep audit entries for (days)** and **Keep security events (days)**).
- Setting `AUDIT_SYSLOG=true` on the server also sends audit events to the syslog (tag `domaindash-audit`) so a copy exists outside the database.

## Good habits

- Leave outbound logging on **Errors only** day to day. Switch to **All requests** while diagnosing an integration, then back.
- Check **Integration Errors** after changing credentials.
- Export evidence before you delete anything.
