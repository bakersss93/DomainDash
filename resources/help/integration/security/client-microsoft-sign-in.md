---
title: Client Microsoft sign-in and the login-method policy
summary: Let client users sign in with their own Microsoft work accounts, and decide whether passwords are still allowed.
category: security
order: 20
audience: integration
related: [staff-sso-saml, users-roles-permissions-mfa, audit-logs-and-retention]
---

Clients do not get their own Entra app and there is no group mapping. You register **one** multi-tenant app in your own Entra tenant. Any client user whose organisation has a Microsoft work or school account can then use **Sign in with Microsoft**, but **only if you already created that user** in {company}. Unknown people are refused and never created.

The full guide, PowerShell snippets and all troubleshooting reasons are in `docs/microsoft-sign-in.md`.

## 1. Register the app in Entra

1. Entra > **Identity > Applications > App registrations > New registration**.
2. **Name**: for example `{company} client sign-in`. Clients see this name on the Microsoft sign-in screen.
3. **Supported account types**: **Accounts in any organizational directory (Any Microsoft Entra ID tenant - Multitenant)**. Do not choose personal Microsoft accounts.
4. **Redirect URI**: platform **Web**, exactly as shown in **Admin > Settings > Single sign-on > Sign in with Microsoft (clients)**. It is `{app_url}/auth/microsoft/callback`.
5. Copy the **Application (client) ID** from the Overview page.
6. **Certificates & secrets > New client secret**. Copy the secret **Value** immediately (shown once). Add a calendar reminder for its expiry: when it expires, client sign-in stops until you create a new secret and save it in {company}.
7. **Authentication**: leave the implicit-grant boxes unticked.
8. **API permissions**: add Microsoft Graph delegated permissions `openid`, `profile`, `email` and `User.Read`.

## 2. Save it in {company}

On **Admin > Settings > Single sign-on > Sign in with Microsoft (clients)**:

- **Application (client) ID** and **Client secret** (encrypted; leave the secret blank later to keep it).
- **Allowed Microsoft tenant IDs (optional)**: empty accepts any organisation (the user must still exist). Fill in to restrict to a short list.
- **Require a tenant ID for each client before its users can sign in with Microsoft**: on by default and recommended. Each client needs its **Allowed Microsoft tenant ID** set on **Admin > Clients > Edit**.
- **Trust Microsoft sign-in for MFA (clients)**: off by default. Only skips {company}'s own MFA when the Microsoft ID token itself says MFA was used (the optional `amr` claim must be added in Entra under **Token configuration**).
- Tick **Enable Sign in with Microsoft for clients** and select **Save Microsoft sign-in**.

### Finding a client's tenant ID

Ask the client's administrator to open the Microsoft Entra admin centre and read **Overview > Tenant ID**. Without their help, open `https://login.microsoftonline.com/contoso.com/v2.0/.well-known/openid-configuration` (replace `contoso.com` with their domain). The GUID in the `issuer` value is the tenant ID.

### Admin consent

If a client's tenant blocks users from approving apps they see "Need admin approval". Their Entra administrator approves once with `https://login.microsoftonline.com/organizations/adminconsent?client_id=<Application (client) ID>`.

## How a client user is matched

- The user's **sign-in name (UPN)** must equal the email of an existing {company} user (upper and lower case ignored).
- On first sign-in {company} links that Microsoft identity (tenant ID and object ID) to the user. From then on only that exact identity can sign in. If someone leaves or moves tenant, open **Admin > Users > Edit** and select **Unlink Microsoft account**.
- Staff, disabled and no-client users are refused.
- Changing a client's tenant ID or sign-in method signs out that client's users straight away.

## Login-method policy

**Admin > Settings > Single sign-on > Login method policy** controls the **password**. Microsoft sign-in is never blocked by it.

| Setting | Choices |
|---|---|
| **Staff** | Password and Microsoft, or Microsoft only |
| **Clients** | Password and Microsoft, or Microsoft only |
| Per client (**Admin > Clients > Edit > Sign-in method**) | Use the global client policy, Password and Microsoft, or Microsoft only |
| Per user (**Admin > Users > Edit**, administrators only) | **May also use a password** (exception) |

Rules:

- The per-user exception always wins. A user with several clients is held to the strictest of them.
- A blocked person who types a correct password sees the same error as for a wrong password, and `auth.password.blocked_by_policy` is written to the audit log.
- {company} refuses to save *Microsoft only* for staff unless an **enabled staff SAML connection** exists **and** at least one **active Administrator** has the password exception (your break-glass login), and refuses it for clients unless Sign in with Microsoft is configured and enabled.

### Recommended roll-out

1. Confirm staff can sign in with Microsoft.
2. Create a break-glass Administrator with a long random password and its own authenticator, tick **May also use a password**, and test it.
3. Switch staff to *Microsoft only*.
4. Switch clients over client by client with the per-client override.

> **Warning:** Always keep a working break-glass Administrator. If a policy locks everyone out, the way back is to sign in as that account and change the policy.

## Troubleshooting

Each failed sign-in shows a short message on the login page and the exact reason is recorded as `auth.microsoft.login.failed` (field `reason`) in **Admin > Audit Logs**.

| `reason` | Meaning and fix |
|---|---|
| `unknown_user` | No {company} user matches the sign-in name. Create the user first. A UPN is not always their mail address. |
| `link_mismatch` | Already linked to a different Microsoft account. Unlink it on the user's edit page if genuine. |
| `tenant_not_configured` | "Require a tenant ID" is on and a client has none. Set it on **Clients > Edit**. |
| `tenant_not_allowed`, `client_tenant_mismatch` | Tenant not on the allowed list, or not the tenant locked to the client. |
| `token_rejected` | Wrong or expired client secret, or the Redirect URI does not match exactly. |
| `bad_audience` | The client ID saved in {company} is not the one Microsoft issued the token for. |
| `idp_error` | Microsoft returned an error, for example consent has not been given. |

Microsoft-side: `AADSTS50011` is a Redirect URI mismatch, `AADSTS700016` an unknown client ID, `AADSTS65001` needs admin consent and `AADSTS7000215` is a wrong client secret.
