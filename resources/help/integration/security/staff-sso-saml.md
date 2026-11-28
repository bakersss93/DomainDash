---
title: Staff single sign-on (Microsoft Entra ID SAML)
summary: Let your team sign in with Microsoft Entra ID, with Entra security groups mapped to {company} roles.
category: security
order: 10
audience: integration
related: [client-microsoft-sign-in, users-roles-permissions-mfa, audit-logs-and-retention]
---

Staff sign in through SAML 2.0 to **your own** Microsoft Entra tenant. Entra security groups decide which {company} role each person gets. You need a Global Administrator or Cloud Application Administrator in Entra, and {company} running on its real https address (`APP_URL`, currently `{app_url}`). It does not work on localhost or plain http.

This article is a short version. The full guide, including PowerShell snippets and the complete troubleshooting tables, is `docs/microsoft-sign-in.md` in the project.

> **Note:** Assigning *groups* to an Entra enterprise application needs Microsoft Entra ID P1 or higher. On the Free tier you can only assign users, and group-to-role mapping will not work as described.

## 1. Create the enterprise application in Entra

1. Sign in at entra.microsoft.com and go to **Identity > Applications > Enterprise applications > New application > Create your own application**.
2. Name it `{company}` and choose **Integrate any other application you don't find in the gallery (Non-gallery)**.
3. Open **Properties** and set **Assignment required?** to **Yes**.
4. Open **Users and groups > Add user/group**. Assign security groups, for example `DD-Administrators` and `DD-Technicians`. Note each group's **Object ID** (Groups > the group > Overview).
5. Open **Single sign-on > SAML**.

## 2. Create the connection in {company}

1. Open **Admin > Settings > Single sign-on** and select the new-connection button under **Staff SAML connections**.
2. **Name**: for example `Staff sign-in` (only admins see it).
3. **Allowed email domains**: your staff email domain(s), one per line, for example `contoso.com`.
4. **Entra tenant ID (Directory ID)**: Entra > Overview > Tenant ID.
5. Select **Create connection**. The page now shows **Values to paste into Microsoft Entra**.

## 3. Finish in Entra

In **Single sign-on > SAML > Basic SAML Configuration > Edit** paste the three values from {company}:

| Entra field | {company} value |
|---|---|
| Identifier (Entity ID) | `{app_url}/saml/<connection-id>/metadata` |
| Reply URL (Assertion Consumer Service URL) | `{app_url}/saml/<connection-id>/acs` |
| Sign-on URL | `{app_url}/saml/<connection-id>/login` |

Leave Relay State and Logout URL empty. The Sign-on URL matters: {company} refuses sign-ins that were not started from {company}.

In **Attributes & Claims**:

- Keep the defaults for object ID, tenant ID and email. If your users have no `mail` value, change the email claim to `user.userprincipalname`.
- **Add a group claim > Groups assigned to the application**, source attribute **Group ID**. Use "assigned to the application" on purpose: with "All groups", users in more than 150 groups get no groups.

In **SAML Certificates**, sign the SAML assertion with SHA-256 and copy the **App Federation Metadata Url**.

## 4. Import metadata and map groups

Back in {company}, open the connection:

1. Paste the **App Federation Metadata URL**, tick **Fetch the metadata URL now and fill the fields below**, and save. (Or paste the XML in **...or paste Federation Metadata XML**.)
2. **Entra group to role mapping**: each staff role is listed. Paste the Object IDs of the groups whose members should get it, one per line. Customer roles are never listed.
3. **Default role (when no group matches)**: leave it on **None (deny)** so people in no mapped group cannot sign in.
4. **Create accounts automatically on first sign-in**: on if staff should be created from Entra, off to require an existing account.
5. **Trust Microsoft for MFA**: tick only if an Entra Conditional Access policy requires MFA for this application.
6. Tick **Enabled** and save. Use **Check configuration**, then **Test sign-in** in a private window.

Roles are re-read from the groups at every sign-in. Removing someone from a group removes the role (and their sessions) at their next sign-in attempt.

## 5. Switch it on per user

The login page asks for the email first and only offers **Single sign-on** to people whose account has **Enable Microsoft sign-in** switched on (**Admin > Users > Edit**, administrators only).

- **Staff created by SAML on first sign-in** get the switch automatically.
- **Staff who already exist** need it ticked once, otherwise they see the normal password form and a SAML attempt is refused as `sso_not_enabled`.
- Their email domain must also be on the enabled connection's **Allowed email domains**.
- The staff person types their email, sees **Single sign-on**, and selects **Log in with Microsoft**. If their policy also allows a password (**Password and Microsoft**, or the user's **May also use a password** exception) a **Use password instead** link is shown too.

Mapped groups can point at the staff roles, **Administrator** and **Technician**. Custom roles created under **Admin > Roles & Permissions** are client-level roles, so they cannot be mapped from Entra groups.

## Certificate rollover

Before the SAML certificate expires: add a **New Certificate** in Entra (inactive), select **Refresh certificates from metadata URL** in {company} so both are trusted, then **Make certificate active** in Entra, test, and remove the old one. **Check configuration** warns 30 days before expiry.

## Safety rules that always apply

- {company} never sees a Microsoft password.
- Staff accounts cannot use client Microsoft sign-in, and client accounts cannot use staff SAML.
- Every attempt, success or failure with the reason, is written to **Admin > Audit Logs** as an `sso.*` security event.
- Single logout is not implemented: logging out of {company} ends the {company} session only.
- Changing `APP_URL` changes the values in Entra. Update all three values.

## Troubleshooting (common)

| Reason in the audit log | What to do |
|---|---|
| `unknown_request` / `no_in_response_to` | Start from the {company} login page or an app tile that uses the Sign-on URL. |
| `invalid_response`, `expired`, `not_yet_valid` | Check the certificate (Check configuration), that Identifier and Reply URL match exactly, and the server clock. |
| `no_group_match` | The user is not in a mapped, application-assigned group. Add them and retry. |
| `groups_overage` | Use "Groups assigned to the application" for the group claim. |
| `domain_not_allowed`, `tenant_mismatch` | Check Allowed email domains and the Entra tenant ID. |

If SSO blocks everyone out, sign in with the local break-glass Administrator. See [Client Microsoft sign-in and login policy](/support/help/security/client-microsoft-sign-in) for how to keep one.
