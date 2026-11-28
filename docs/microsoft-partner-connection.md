# Microsoft Partner connection (CSP / GDAP customer tenants)

Settings > Microsoft Partner lets an MSP read its list of Microsoft customer tenants and map each one to a DomainDash client. The mapping fills `clients.microsoft_tenant_id`, which is what locks a client's users to their own Microsoft tenant for client Microsoft sign-in (see `microsoft-sign-in.md`). It is read-only towards Microsoft and works for any MSP that is a Microsoft partner.

## 1. Which Graph endpoint and permission

Both are verified against Microsoft Learn (Graph v1.0, application permission, client credentials):

| Source (setting) | Endpoint | Least-privileged application permission | Returns |
|---|---|---|---|
| Delegated admin customers (default) | `GET /tenantRelationships/delegatedAdminCustomers` | `DelegatedAdminRelationship.Read.All` | `id` (the customer tenant ID), `displayName` |
| Contracts | `GET /contracts` | `Directory.Read.All` | `customerId` (tenant ID), `defaultDomainName`, `displayName` |
| Both | both calls, merged by tenant ID | both permissions | name plus default domain |

Recommendation: start with the default (narrow permission). `Directory.Read.All` is a broad directory permission, so use the Contracts source only if you want the customer's default domain, which improves automatic suggestions (the delegated endpoint gives no domain). `/contracts` only lists customers of a legacy reseller (CSP) relationship; `delegatedAdminCustomers` lists GDAP customers. If you have both kinds pick "Both".

## 2. Register the app in YOUR partner tenant

1. Entra admin centre of the partner tenant > App registrations > New registration. Name it for example "DomainDash partner sync". Supported account types: single tenant. No redirect URI.
2. Copy the Directory (tenant) ID and the Application (client) ID.
3. Certificates and secrets > New client secret. Copy the secret VALUE (not the secret ID) now; it is shown once. Note the expiry date and rotate before it.
4. API permissions > Add a permission > Microsoft Graph > Application permissions > add `DelegatedAdminRelationship.Read.All` (and `Directory.Read.All` only if you chose Contracts/Both).
5. Click Grant admin consent for the partner tenant. A Global Administrator must do this; without it Microsoft answers 403.

## 3. Configure DomainDash

Settings > Microsoft Partner > Connection: enter the partner tenant ID, application ID and secret, choose the source, Save. The secret is stored encrypted, never shown again (the field shows `********`) and left unchanged when you save with the field untouched or blank. Use Test connection to confirm a token is issued and the list is readable, then Sync now.

Sync runs on the queue worker like the other integrations (Sync scheduler shows runs and worker health). To refresh automatically enable "Sync Microsoft partner customer tenants" under Settings > Sync scheduler.

## 4. Mapping tenants to clients

Settings > Microsoft Partner > Customer tenants lists every tenant. For unmapped tenants DomainDash suggests a client:

1. Domain match: the tenant's default domain equals a domain managed for the client, or the e-mail domain of the client or its users (free mail providers such as gmail.com are ignored).
2. Name similarity: the tenant display name or `*.onmicrosoft.com` prefix resembles the client's business name (legal suffixes like Pty Ltd are ignored; threshold 82%).

Suggestions are never applied automatically. An administrator confirms the suggestion, picks another client, changes or clears a mapping. Rules:

- One tenant maps to one client, and a client has one tenant. Mapping a tenant that belongs to another client moves it; mapping a client that already has a tenant replaces that tenant.
- Any change signs out the affected client's users (their sessions were issued under the old trust) and is audited (`client.sso_settings.changed` plus `partner.mapping.confirmed` / `partner.mapping.cleared`).
- The client's edit page shows the mapped partner customer. Tenants that drop out of Microsoft's list are flagged "Not in last sync" but their mapping is kept.

## 5. Security notes

- Fixed hosts only: `login.microsoftonline.com` (token) and `graph.microsoft.com` (data). Redirects are never followed. `@odata.nextLink` is followed only when it is https on exactly `graph.microsoft.com` (default port, no credentials); anything else stops the sync.
- Tenant and application IDs must be GUIDs before they reach a URL.
- The access token is cached encrypted until shortly before expiry; rotating the secret invalidates the cache. Secrets never reach the audit log, logs or error messages (Microsoft errors are reduced to the AADSTS code or Graph error code plus a fixed hint).
- Screens and endpoints are Administrator only.
- Audit events: `partner.settings.updated`, `partner.sync.started|completed|failed|test_ok|test_failed`, `partner.mapping.confirmed|cleared`.

## 6. Troubleshooting

| Message | Cause |
|---|---|
| AADSTS7000215 | Wrong secret (use the VALUE, not the ID) |
| AADSTS7000222 | Secret expired |
| AADSTS700016 / AADSTS90002 | Application ID or tenant ID not found |
| HTTP 403 Authorization_RequestDenied | Permission missing or admin consent not granted |
| Empty list | The partner has no customers of that relationship type; try "Both" |

## 7. Future ideas (not built)

- Licence counts per customer (`/subscribedSkus` in the customer tenant through GDAP, or partner centre APIs) to reconcile against Halo billing.
- Auto-create clients from unmapped tenants, GDAP relationship expiry warnings, and showing tenant health on the client dashboard.
