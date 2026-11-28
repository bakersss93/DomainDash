---
title: Users, roles, permissions and the MFA policy
summary: Create users, give them roles, grant or revoke permissions per role and set multi-factor authentication rules.
category: security
order: 50
audience: integration
related: [staff-sso-saml, client-microsoft-sign-in, api-keys-and-scopes]
---

## Roles in {company}

| Role | For | Notes |
|---|---|---|
| **Administrator** | Owners and senior staff | Has every permission. Can use the whole **Admin** menu. |
| **Technician** | Your support staff | Works with the permissions assigned to the role. Staff role. |
| **Customer** | Your clients' users | Sees only the clients they are assigned to. |

You can add more roles. A role is just a named bundle of permissions.

## Create a user

1. **Admin > Users**, then the create button. (The list can be filtered by role and shows **Status** and **MFA**.)
2. **Personal Information**: **First Name**, **Last Name**, **Email Address**.
3. **Access Controls**:
   - **Permission Group**: the role.
   - **Enable user account**: disabled users cannot log in.
   - **MFA Policy**: see below.
   - **Client Organisation(s)**: for customers, tick the client(s) whose data they may see. A customer with no client sees nothing.
4. **Password & Security > Set Password** is optional. Leave it empty and send a reset link later.
5. **Save user**.

On **Edit User** you can also use **Reset Password** (set a password or **Send reset email**), **Unlink Microsoft account**, the sign-in policy exception **May also use a password**, impersonate the user (needs `users.impersonate`; sessions end after 60 minutes by default) and **Delete User**.

> **Warning:** Public registration is switched off. All accounts are created by administrators (or the API).

## Roles and permissions

**Admin > Roles & Permissions** shows one card per role with a checkbox for every permission.

- Tick or untick permissions and select **Save permissions** for that role.
- **Create role/group** adds a new role. **Delete role** works only for roles with no users, and never for Administrator.

### Staff-only permissions

These can only be held by **Administrator** and **Technician**. If you try to grant one to any other role the page refuses with "These permissions are staff-only":

`domains.manage`, `domains.transfer`, `domains.register`, `domains.renew`, `services.manage`, `ssl.manage`, `internet.manage`, `clients.view`, `clients.manage`, `users.view`, `users.manage`, `users.impersonate`, `settings.manage`, `apikeys.manage`, `analytics.view`, `sync.run`, `domain-pricing.view`, `domain-pricing.manage`, `help.integrations.view`.

### Customer-facing permissions

| Permission | What it controls for customers |
|---|---|
| `domains.view` | The **Domains > Manage** list |
| `dns.manage` | Adding, editing and deleting DNS records and DNS options (not given to Customer by default) |
| `services.view`, `ssl.view` | Service and SSL visibility |
| `tickets.create` | Granted to Customer by default |
| `internet.view` | **Internet** pages (Customer by default) |
| `internet.diagnostics` | The connection test (also needs the per-client option) |
| `internet.order`, `internet.cancel` | Requesting a new service or a cancellation (also need the Vocus customer-request options) |

Customer help articles that describe a feature are hidden from users who lack that feature's permission.

### Help permission

`help.integrations.view` ("Help: integration set-up guides") controls the integration articles in **Support > Help**, including this one. Administrators and Technicians have it by default. Untick it on the Technician card and **Save permissions** to hide those articles from technicians.

## MFA policy

- **Roles that must use MFA:** by default **Administrator** and **Technician**, set by `MFA_REQUIRED_ROLES` in `.env`. Their users can only reach the MFA setup page until they enrol.
- **Per user (MFA Policy):** **Enabled (user can configure)**, **Enforced (required)** or **Disabled**. Disabled is not accepted for roles that must use MFA.
- **Global defaults:** **Admin > Settings > MFA and audit > Multi-factor**:
  - **Remember a browser for (days)** (1 to 365), with **Allow remembered devices**.
  - **Primary method**: authenticator app (TOTP) or email one-time passcode. Enrolment and sign-in use the authenticator app in this version.
  - **Issuer label**: the name shown in the authenticator app.
  - **Allow recovery codes**.
- **Microsoft sign-in:** you can trust Microsoft's own MFA per staff connection or for clients. Default is off. See [Staff SSO](/support/help/security/staff-sso-saml).

Users enrol themselves: see the customer article [Setting up and using MFA](/support/help/getting-started/setting-up-mfa).

### Locked-out user

- A user who lost their phone: an administrator can reset their MFA from the **Admin > Users** list with the **Reset MFA** icon, then the user enrols again.
- A locked-out administrator: on the server run `php artisan domaindash:mfa-reset admin@example.com`. As a last resort set `MFA_ENFORCE=false` in `.env`, run `php artisan config:clear`, fix the problem, then set it back.

Every sign-in, MFA event, impersonation and permission change is written to [Audit Logs](/support/help/security/audit-logs-and-retention).
