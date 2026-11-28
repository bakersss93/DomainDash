# Sign in with Microsoft: staff (SAML) and clients (Microsoft work accounts)

This guide is for whoever runs DomainDash. You do not need to know SAML or OpenID Connect. You need a Global
Administrator or Cloud Application Administrator in your own Microsoft Entra tenant, and DomainDash running on its
real **https** address (`APP_URL` in `.env`, for example `https://portal.example.com`). Neither method works on
`localhost` or plain http.

DomainDash is not tied to any one company. Every name, domain and URL below comes from your own settings and `APP_URL`.

## How it works, in plain English

There are two separate ways to sign in with Microsoft. People never see which one is used: they type their work email
(or just click the button) on the login page and DomainDash picks the right one.

| | Staff (your team) | Clients (your customers' users) |
|---|---|---|
| Method | SAML 2.0 to **your own** Entra tenant | OpenID Connect to **their own** Entra tenant |
| Entra object you create | One *enterprise application* | One *multi-tenant app registration* |
| Entra groups mapped to roles? | Yes | **No** |
| Accounts created automatically? | Optional (per connection) | **Never.** You create the user in DomainDash first |
| Chosen when the email domain is | one of the staff connection's domains | anything else |

Safety rules that always apply:

* DomainDash never sees a Microsoft password.
* A client user can only ever see their own client's data. Microsoft sign-in does not change that: it only proves who the person is.
* Staff accounts cannot use client sign-in, and client accounts cannot use staff sign-in.
* Every Microsoft sign-in (success or failure, with the reason) is written to **Audit Logs** and kept as a security event.

---

# Part 1. Staff sign-in (SAML) with Entra group mapping

You will do some work in Entra, some in DomainDash, then finish in Entra. DomainDash only shows the three URLs for Entra
after the connection has been saved once, so expect to visit Entra twice.

Licensing note: assigning *groups* to an enterprise application needs Microsoft Entra ID P1 or higher. On the Free tier
you can only assign individual users and the groups claim will not work as described.

## 1A. Create the enterprise application in Entra

1. Sign in at <https://entra.microsoft.com>. Go to **Identity > Applications > Enterprise applications >
   New application > Create your own application**.
2. Name it `DomainDash`. Choose **Integrate any other application you don't find in the gallery (Non-gallery)**. Create.
3. Open **Properties** and set **Assignment required?** to **Yes**. Save. (Only assigned people can sign in.)
4. Open **Users and groups > Add user/group**. Create (or reuse) security groups, for example `DD-Administrators`
   and `DD-Technicians`, and assign them to the app. Note each group's **Object ID** (Groups > the group > Overview).
5. Open **Single sign-on > SAML**.

## 1B. Create the connection in DomainDash

1. **Settings > Single sign-on > Staff SAML connection**.
2. **Name**: for example `Staff sign-in` (only admins see it).
3. **Allowed email domains**: your staff email domain(s), one per line, for example `contoso.com`.
4. **Entra tenant ID**: Entra > Overview > Tenant ID.
5. Save. The page now shows **Values to paste into Microsoft Entra**.

## 1C. Finish in Entra

In the enterprise app, **Single sign-on > SAML > Basic SAML Configuration > Edit**:

| Entra field | Value (copy from the DomainDash connection page) |
|---|---|
| Identifier (Entity ID) | `https://<your-domain>/saml/<connection-id>/metadata` |
| Reply URL (Assertion Consumer Service URL) | `https://<your-domain>/saml/<connection-id>/acs` |
| Sign-on URL | `https://<your-domain>/saml/<connection-id>/login` |

Leave Relay State and Logout URL empty. The Sign-on URL matters: DomainDash refuses sign-ins that were not started from
DomainDash, so the app tile in myapps.microsoft.com must start from this URL.

**Attributes & Claims > Edit**:

* Keep the defaults for object ID, tenant ID and email. If your users have no `mail` value, change the email claim to `user.userprincipalname`.
* **Add a group claim > Groups assigned to the application**, Source attribute **Group ID**. Use "assigned to the application" on purpose: with "All groups", a user in more than 150 groups gets no groups at all.

**SAML Certificates**: signing option **Sign SAML assertion**, algorithm SHA-256. Copy the **App Federation Metadata Url**.

## 1D. Import metadata and map groups

Back in DomainDash, open the connection:

1. Paste the **App Federation Metadata URL**, tick **Fetch the metadata URL now**, save. (Or paste the downloaded Federation Metadata XML.)
2. **Entra group to role mapping**: DomainDash lists every **staff role** (read from the roles table, so custom roles appear
   automatically; customer roles are never listed). For each role paste the Object IDs of the groups whose members should get it, one per line.
3. **Default role**: leave on *None (deny)* so people in no mapped group cannot sign in.
4. **Create accounts automatically on first sign-in**: on if you want staff created from Entra; off to require an existing account.
5. **Trust Microsoft for MFA**: tick only if an Entra Conditional Access policy requires MFA for the DomainDash app.
6. Tick **Enabled**, save, then use **Check configuration** and **Test sign-in** in a private window.

Roles are re-read from the groups on **every** sign-in. Removing someone from the group removes the role (and their sessions)
at their next sign-in attempt. Signing in again with no mapped group is refused.

## 1E. Certificate rollover (before the 3-year expiry)

1. Entra > SAML Certificates > **New Certificate** (inactive). 2. In DomainDash click **Refresh certificates from metadata URL**
(or paste it into *Second signing certificate*) and save; both are now trusted. 3. In Entra **Make certificate active**.
4. Test, then remove the old certificate. **Check configuration** warns 30 days before expiry.

## 1F. Optional: PowerShell (Microsoft.Graph) for the staff app

> **Untested.** These snippets were written from Microsoft's documentation and have not been run against a live tenant.
> Try them in a test tenant first. The portal steps above are the supported path.

```powershell
Install-Module Microsoft.Graph -Scope CurrentUser
Connect-MgGraph -Scopes "Application.ReadWrite.All","AppRoleAssignment.ReadWrite.All","Group.Read.All","Organization.Read.All"

(Get-MgOrganization).Id                                        # tenant ID
Get-MgGroup -Filter "startswith(displayName,'DD-')" | Select-Object DisplayName, Id   # group object IDs

# Non-gallery enterprise app, switched to SAML
$tpl = Invoke-MgGraphRequest -Method POST -Uri "https://graph.microsoft.com/v1.0/applicationTemplates/8adf8e6e-67b2-4cf2-a259-e3dc5476c621/instantiate" `
        -Body (@{ displayName = "DomainDash" } | ConvertTo-Json)
Update-MgServicePrincipal -ServicePrincipalId $tpl.servicePrincipal.id -PreferredSingleSignOnMode "saml"

# Paste YOUR values from the DomainDash connection page
$entityId = "https://portal.example.com/saml/<connection-id>/metadata"
$acs      = "https://portal.example.com/saml/<connection-id>/acs"
Update-MgApplication -ApplicationId $tpl.application.id -IdentifierUris @($entityId) -Web @{ RedirectUris = @($acs) } -GroupMembershipClaims "ApplicationGroup"
```

The Sign-on URL and claim details are quickest in the portal.

---

# Part 2. Client sign-in (Microsoft work accounts, multi-tenant)

Clients do **not** get their own Entra app and there is **no group mapping**. You register **one** multi-tenant app in your
own tenant. Any client user whose organisation has a Microsoft work or school account can then use "Sign in with Microsoft",
**but only if you already created that user in DomainDash** (**Users > Create**, assigned to the client). Unknown people are refused and never created.

## 2A. Register the app in Entra

1. Entra > **Identity > Applications > App registrations > New registration**.
2. **Name**: for example `DomainDash client sign-in` (clients see this name on the Microsoft consent/sign-in screen).
3. **Supported account types**: **Accounts in any organizational directory (Any Microsoft Entra ID tenant - Multitenant)**.
   Do not choose personal Microsoft accounts.
4. **Redirect URI**: platform **Web**, value exactly as shown in **Settings > Single sign-on > Sign in with Microsoft (clients)**.
   It is `APP_URL` + `/auth/microsoft/callback`, for example `https://portal.example.com/auth/microsoft/callback`. Register.
5. Copy the **Application (client) ID** from the Overview page.
6. **Certificates & secrets > New client secret**. Copy the secret **Value** immediately (it is shown once). Put a reminder in your calendar for its expiry: after that date client sign-in stops until you create a new secret and save it in DomainDash.
7. **Authentication**: nothing to tick. DomainDash uses the authorization-code flow with PKCE, and Microsoft returns the ID token from the token endpoint, so the implicit-grant boxes must stay **unticked**.
8. **API permissions**: **Add a permission > Microsoft Graph > Delegated permissions**: `openid`, `profile`, `email` and `User.Read`. These need no admin consent in your tenant.
9. Nothing else is needed. (Optional: an `xms_edov` ID-token claim is only used if present; DomainDash matches on the sign-in name instead.)

## 2B. Save it in DomainDash

**Settings > Single sign-on > Sign in with Microsoft (clients)**:

* **Application (client) ID** and **Client secret** (stored encrypted; leave the box blank later to keep the stored secret).
* **Allowed Microsoft tenant IDs (optional)**: leave empty to accept any organisation (the user must still exist). Fill it in to restrict sign-in to a short list of tenants.
* **Require a tenant ID for each client before its users can sign in with Microsoft**: on by default (recommended). While on, a user is refused (`tenant_not_configured`), at first link and at every later sign-in, unless **every** client they belong to has its *Microsoft tenant ID* set (Clients > Edit). The global allowed-tenants list does **not** substitute for it. Turning it off restores the old behaviour (a client with no tenant accepts any tenant, and the tenant is recorded when its single user first links); the settings page then shows a warning because anyone controlling any Microsoft tenant could claim a client user whose email matches their sign-in name.
* **Trust Microsoft sign-in for MFA (clients)**: **off by default**. Microsoft v2 ID tokens only contain the `amr` (authentication methods) claim if you add it as an optional claim on the app registration, and Microsoft documents it as not reliably present, so trust cannot be assumed. When on, DomainDash skips its own MFA only if the ID token's `amr` contains `mfa`; otherwise (including when `amr` is missing) DomainDash MFA is still required. To use it, add the optional claim: app registration > **Token configuration > Add optional claim > ID > amr**.
* Tick **Enable** and save.

## 2C. Client tenants may need admin consent

When a user from a client's tenant signs in for the first time, Microsoft may show "Need admin approval" if that tenant
blocks users from consenting to apps. The client's Entra administrator then has to approve the app once, using this link
(replace the ID):

`https://login.microsoftonline.com/organizations/adminconsent?client_id=<Application (client) ID>`

The permissions requested are only sign-in and basic profile. If the client's admin prefers, they can also pre-approve it in their
tenant under **Enterprise applications > DomainDash client sign-in > Permissions > Grant admin consent**.

## 2D. How a client user is matched

* **First sign-in**: Microsoft sends a verified identity. DomainDash matches the user's **sign-in name (UPN, `preferred_username`)** to the email
  of an existing DomainDash user, ignoring upper/lower case. The `email` claim is deliberately **not** used (Microsoft says it can be changed by a
  tenant admin, which would let a hostile tenant claim someone else's address), unless Microsoft marks it as domain-verified (`xms_edov`).
* On success DomainDash stores the person's **tenant ID and object ID**. From then on that user can only sign in with that exact Microsoft
  identity. A different Microsoft account with the same name is refused, whatever the email says. If someone leaves or changes tenant, an
  administrator opens **Users > Edit** and clicks **Unlink Microsoft account**; the next sign-in links again.
* Staff accounts, disabled accounts and accounts with no client are refused.
* **Each client needs its tenant ID** (required by default, see 2B): on **Clients > Edit**, *Allowed Microsoft tenant ID*. With the requirement on,
  users of a client without a tenant ID cannot sign in or link (`tenant_not_configured`) and nothing is filled in automatically. Enter the
  client's tenant ID yourself before inviting their users. Only if you switch the requirement off does DomainDash fill the tenant in on the first link of a single-client user.
* **Finding a client's tenant ID**: ask the client's administrator to open the Microsoft Entra admin center (<https://entra.microsoft.com>) and read **Overview > Tenant ID**.
  Without their help, open `https://login.microsoftonline.com/<their domain, e.g. contoso.com>/v2.0/.well-known/openid-configuration` in a browser: the
  `issuer` value is `https://login.microsoftonline.com/<GUID>/v2.0`, and that GUID is the tenant ID.
* Changing a client's tenant ID or sign-in method signs out all of that client's users straight away.
* A signed-in client user can see their linked Microsoft account in **Account Settings**.

## 2E. Optional: PowerShell (Microsoft.Graph) for the app registration

> **Untested.** Written from Microsoft's documentation, not run against a live tenant. Prefer the portal steps above, or test in a trial tenant first.

```powershell
Connect-MgGraph -Scopes "Application.ReadWrite.All"

$redirect = "https://portal.example.com/auth/microsoft/callback"   # APP_URL + /auth/microsoft/callback
$graph = "00000003-0000-0000-c000-000000000000"                    # Microsoft Graph
$app = New-MgApplication -DisplayName "DomainDash client sign-in" -SignInAudience "AzureADMultipleOrgs" `
        -Web @{ RedirectUris = @($redirect) } `
        -RequiredResourceAccess @(@{ ResourceAppId = $graph; ResourceAccess = @(
            @{ Id = "37f7f235-527c-4136-accd-4a02d197296e"; Type = "Scope" },   # openid
            @{ Id = "14dad69e-099b-42c9-810b-d002981feec1"; Type = "Scope" },   # profile
            @{ Id = "64a6cdd6-aab1-4aaf-94b8-3cc8405e90d0"; Type = "Scope" },   # email
            @{ Id = "e1fe6dd8-ba31-4d61-89e7-88639da4683d"; Type = "Scope" }    # User.Read
        ) })
$app.AppId                                                           # Application (client) ID
$secret = Add-MgApplicationPassword -ApplicationId $app.Id -PasswordCredential @{ DisplayName = "DomainDash"; EndDateTime = (Get-Date).AddMonths(12) }
$secret.SecretText                                                   # shown once: paste into DomainDash
```

---

# Part 3. Login-method policy

**Settings > Single sign-on > Login method policy** controls the **password**. Microsoft sign-in is never blocked by it.

| Setting | Choices |
|---|---|
| Staff | *Password and Microsoft* or *Microsoft only* |
| Clients | *Password and Microsoft* or *Microsoft only* |
| Per client (Clients > Edit > Sign-in method) | *Use the global client policy*, *Password and Microsoft*, *Microsoft only* |
| Per user (Users > Edit, administrators only) | **May also use a password** (exception) |

Rules:

* The exception always wins over the policy. A user with several clients is held to the strictest of them.
* When a blocked person types a correct password they see the **same** error as for a wrong password, and DomainDash records `auth.password.blocked_by_policy` in the audit log.
* DomainDash **refuses to save** *Microsoft only*:
  * for staff, unless an **enabled staff SAML connection** exists **and** at least one **active Administrator** has the password exception (your break-glass login);
  * for clients (globally or for one client), unless **Sign in with Microsoft is configured and enabled**.
* While a policy depends on them, you cannot disable the last staff SAML connection, turn off client Microsoft sign-in, or remove/deactivate/delete the last password-exception Administrator.

Recommended roll-out: confirm staff can sign in with Microsoft, create a break-glass Administrator with a long random password and its own authenticator (tick *May also use a password*), test it, then switch staff to *Microsoft only*. Switch clients over client by client using the per-client override.

Note: re-entering your password for sensitive actions (revealing an EPP code and similar) still needs a local password. A person on *Microsoft only* can set one with "Forgot your password?"; it will not let them sign in.

To undo a policy in an emergency, sign in with the break-glass Administrator and change it, or run `php artisan tinker` and `App\Models\Setting::put('sso', ['staff_login' => 'both', 'client_login' => 'both']);`.

## MFA with Microsoft sign-in

* Staff (SAML): per connection, *Trust Microsoft for MFA* (default off).
* Clients: global setting *Trust Microsoft sign-in for MFA (clients)* (default **off**). When on it only applies if the ID token's `amr` claim contains `mfa`.
* Trust is checked on **every request**: switching it off, disabling the feature, unlinking the user, or changing them into a staff user ends or tightens their current session straight away.

---

# Troubleshooting

DomainDash shows a short message on the login page and records the exact reason in **Audit Logs**. Staff SAML events are `sso.*`
(`sso.login.failed`, field `reason`); client Microsoft events are `auth.microsoft.login.success`, `auth.microsoft.login.failed`
(field `reason`), `auth.microsoft.linked` and `auth.microsoft.unlinked`.

## Client Microsoft sign-in reasons

| `reason` | Meaning and fix |
|---|---|
| `disabled` | Sign in with Microsoft is off or missing the client ID / secret. |
| `no_flow`, `flow_expired`, `state_mismatch` | The sign-in took more than 10 minutes, was opened in a different browser, or was used twice. Start again from the login page. |
| `token_rejected` | Microsoft refused the code. Usually an expired or wrong **client secret**, or a Redirect URI that does not match exactly. |
| `token_unreachable`, `jwks_unreachable` | DomainDash could not reach login.microsoftonline.com (firewall / proxy). |
| `bad_signature`, `bad_issuer`, `bad_audience`, `nonce_mismatch`, `token_expired`, `token_not_yet_valid` | The identity token failed a check. `bad_audience` means the Application (client) ID saved in DomainDash is not the one in the token. `token_expired` / `not_yet_valid` usually means the server clock is wrong (fix NTP). |
| `unknown_user` | No DomainDash user matches the sign-in name. Create the user (email = their Microsoft sign-in name) first. Remember a UPN is not always their mail address. |
| `link_mismatch` | The user is already linked to a different Microsoft account. Unlink it on the user's edit page if the change is genuine. |
| `staff_user` | Staff use their organisation login (SAML); enter the work email so DomainDash routes to it. |
| `no_client`, `inactive` | The user has no client assigned, or is disabled. |
| `tenant_not_configured` | "Require a tenant ID for each client" is on and one of the user's clients has no Microsoft tenant ID. Set it on the client's edit page (see 2D). |
| `tenant_not_allowed`, `client_tenant_mismatch` | The person's Microsoft tenant is not on the allowed list, or is not the tenant locked to their client. |
| `identity_in_use` | That Microsoft account is already linked to another DomainDash user. |
| `idp_error` | Microsoft returned an error (for example the user cancelled, or the client's admin has not consented). See 2C. |

Microsoft-side errors: `AADSTS50011` Redirect URI mismatch (re-copy it exactly, including https); `AADSTS700016` wrong or unknown client ID;
`AADSTS65001` / "Need admin approval" the client tenant must grant consent (2C); `AADSTS7000215` wrong client secret.

## Staff SAML reasons

| Reason in audit log / message | Meaning and fix |
|---|---|
| `unknown_request` "attempt has expired or was not started here" | Took more than 10 minutes, used twice, or not started from DomainDash. Start from the login page or an app tile with the Sign-on URL. |
| `no_in_response_to` | IdP-initiated sign-in. Set the Sign-on URL in Entra and launch from DomainDash. |
| `invalid_response` | Signature, audience, destination, issuer or timing failed. Check the certificate (Check configuration), that Identifier and Reply URL match exactly, and the server clock. |
| `expired` / `not_yet_valid` | Server clock is out by more than about a minute. |
| `replay` | The same response was submitted twice (browser Back button). |
| `tenant_mismatch` | Signed in with a different Entra tenant than the connection's. |
| `domain_not_allowed` | The email domain is not on the connection. |
| `no_group_match` | Not in a mapped, application-assigned group. Add them, wait a minute, retry. Their roles were removed and sessions ended. |
| `groups_overage` | Too many groups for the token: use **Groups assigned to the application**. |
| `no_object_id` / `no_email` | Claims missing; re-check Attributes & Claims. |
| `client_user_on_staff_connection`, `microsoft_linked_user`, `already_linked`, `wrong_connection` | Safety rules stopped an account being linked. Fix the user in DomainDash rather than loosening the connection. |
| `inactive`, `jit_disabled` | User disabled, or auto-creation is off and no account exists. |

Other notes:

* Single logout is not implemented: logging out of DomainDash ends the DomainDash session only.
* Behind a reverse proxy, make sure Laravel trusts it (`X-Forwarded-Proto: https`), or generated URLs will be `http://` and Microsoft will reject them.
* **Changing `APP_URL` changes the values in Entra.** Update the SAML Identifier / Reply URL / Sign-on URL **and** the app registration's Redirect URI.
* The only CSRF-exempt route is the SAML Reply URL (protected by the signed assertion). The client callback is a normal GET protected by a one-time `state`, `nonce` and PKCE.

## Email-first login and per-user enablement

* The login page asks for the email first. Unknown, inactive, non-SSO and "SSO unavailable" addresses all get the same
  password view. An SSO-enabled account gets "Single sign-on" with **Log in with Microsoft**; if its policy allows a password
  there is also **Use password instead**. With JavaScript the view switches while typing (`POST /login/method`, 20/min per IP,
  uniform minimum response time, local database reads only); without it the **Continue** button does the same server side.
* **Enable Microsoft sign-in** (user edit page, Administrators) is required for every EXISTING user on both SAML and client
  Microsoft sign-in; refusals are audited as `sso_not_enabled`. Staff created by SAML JIT get it automatically.
* "Keep me logged in" carries through both SSO paths (SAML stores it with the pending request, OIDC in the session).
* A role is staff-class if it is Administrator/Technician or holds any staff-only permission (`StaffPermissions::STAFF_ONLY`).
