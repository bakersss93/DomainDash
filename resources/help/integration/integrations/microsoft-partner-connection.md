---
title: Microsoft Partner connection (customer tenants)
summary: Read your Microsoft CSP/GDAP customer tenants and map each one to a client, so client Microsoft sign-in can lock users to their own tenant.
category: integrations
order: 65
audience: integration
related: [client-microsoft-sign-in, sync-scheduler, users-roles-permissions-mfa]
---

If your organisation is a Microsoft partner (CSP reseller or GDAP), {company} can read your list of customer tenants and let you map each tenant to a client. The mapping fills the client's **Allowed Microsoft tenant ID**, which is what keeps that client's users to their own Microsoft tenant for [client Microsoft sign-in](/support/help/security/client-microsoft-sign-in). The connection is **read-only** towards Microsoft. You can skip it and type tenant IDs by hand on **Admin > Clients > Edit** instead.

**Where:** **Admin > Settings > Integrations > Microsoft Partner** (tabs **Connection** and **Customer tenants**). Administrators only.

## 1. Choose the customer list source

| Source | What it reads | Application permission needed | What you get |
|---|---|---|---|
| **Delegated admin customers** (default) | `/tenantRelationships/delegatedAdminCustomers` | `DelegatedAdminRelationship.Read.All` | Tenant ID and name |
| **Contracts** | `/contracts` | `Directory.Read.All` | Tenant ID, name and default domain |
| **Both** | Both calls, merged by tenant ID | Both permissions | Name and default domain |

Start with the default: its permission is narrow. `Directory.Read.All` is a broad directory permission, so choose Contracts only if you want the customer's default domain (it improves automatic suggestions). Contracts only lists customers of a legacy reseller relationship; the default lists GDAP customers. If you have both kinds, choose **Both**.

## 2. Register an app in your own (partner) tenant

1. In your Entra admin centre go to **App registrations > New registration**. Name it for example `{company} partner sync`, with **single tenant** and no redirect URI.
2. Copy the **Directory (tenant) ID** and the **Application (client) ID**.
3. **Certificates & secrets > New client secret.** Copy the secret **Value** (not the secret ID) straight away. Note its expiry date and replace it before then.
4. **API permissions > Add a permission > Microsoft Graph > Application permissions.** Add the permission(s) from the table above.
5. Select **Grant admin consent** for your tenant. A Global Administrator must do this, otherwise Microsoft answers 403.

## 3. Save the connection

On the **Connection** tab enter **Partner tenant ID**, **Application (client) ID**, **Client secret** and **Customer list source**, then select **Save connection**. The secret is stored encrypted and never shown again; leave the field untouched to keep it.

Select **Test connection** to check that a token is issued and the list can be read, then **Sync now**. Syncs run on the queue worker like the other integrations (see [Sync scheduler](/support/help/platform/sync-scheduler)). To refresh automatically, switch on **Sync Microsoft partner customer tenants** under **Admin > Settings > Sync scheduler**.

## 4. Map tenants to clients

The **Customer tenants** tab lists every tenant, with a search box and filter. For unmapped tenants {company} shows a **Suggested** client, found by:

1. **Domain match**: the tenant's default domain equals a domain managed for the client, or the email domain of the client or its users (free providers such as gmail.com are ignored).
2. **Name similarity**: the tenant name or its `*.onmicrosoft.com` prefix resembles the client's business name (suffixes such as Pty Ltd are ignored).

Suggestions are never applied automatically. Select **Confirm suggestion**, or type a client and select **Map** or **Change**, or select **Clear** to remove a mapping.

- One tenant maps to one client, and a client has one tenant. Mapping a tenant that belongs to another client moves it; mapping a client that already has a tenant replaces that tenant.
- Any change sets the client's **Allowed Microsoft tenant ID**, signs that client's users out straight away and is written to the audit log.
- The mapped tenant is shown on the client's edit page. A tenant that drops out of Microsoft's list is flagged **Not in last sync**; its mapping is kept.

> **Tip:** After mapping, make sure the users who should use Microsoft sign-in have **Enable Microsoft sign-in** switched on. See [Client Microsoft sign-in](/support/help/security/client-microsoft-sign-in).

## Safety

- Only the fixed Microsoft hosts (`login.microsoftonline.com` and `graph.microsoft.com`) are contacted, and redirects are never followed.
- The access token is cached encrypted until shortly before it expires. Secrets never appear in the audit log or in error messages.
- Audit events: `partner.settings.updated`, `partner.sync.*` and `partner.mapping.confirmed` / `partner.mapping.cleared`.

## Troubleshooting

| Message | Cause |
|---|---|
| AADSTS7000215 | Wrong secret. Use the secret **Value**, not the ID. |
| AADSTS7000222 | The secret has expired. Create a new one. |
| AADSTS700016 / AADSTS90002 | Application ID or tenant ID not found. |
| HTTP 403 Authorization_RequestDenied | Permission missing or admin consent not granted. |
| Empty list | You have no customers of that relationship type. Try **Both**. |

The full technical guide, including ideas not yet built (licence counts, GDAP expiry warnings), is `docs/microsoft-partner-connection.md` in the project.
