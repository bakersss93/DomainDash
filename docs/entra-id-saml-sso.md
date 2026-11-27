# Microsoft Entra ID single sign-on (SAML 2.0) for DomainDash

This guide is for the DomainDash owner/administrator. You do not need to know SAML. You need
(1) a Global Administrator or Cloud Application Administrator in Microsoft Entra, and
(2) DomainDash running on its real **https** address (SAML will not work on `localhost` or plain http).

## How it works, in plain English

* Staff and each client sign in with Microsoft. DomainDash never sees their Microsoft password.
* DomainDash has **connections**. One *staff* connection for your own Entra tenant, and one *client* connection
  per client that wants to use their own Entra tenant.
* A person's **Entra security groups decide their DomainDash role** (for example group "DD-Technicians" gives the
  Technician role). Roles are re-checked on every sign-in: remove someone from the group and they lose the role
  (and are signed out everywhere) at their next sign-in attempt.
* Accounts are created automatically the first time someone signs in (you can switch that off per connection).
* A client connection can only ever create or sign in **Customer** users that belong to **that client**. This is
  enforced when you save the connection and again on every sign-in, so a client's Entra admin cannot give anyone
  staff access or see another client's data.
* You keep a **break-glass administrator** that signs in with a local password, so an Entra outage or a
  mistake never locks you out.

## Before you start

1. Check `APP_URL` in DomainDash's `.env` is the real address, e.g. `https://portal.example.com`, and that the
   site is served over https. The values you paste into Entra are generated from `APP_URL`.
2. Order of work: do Part A in Entra first (you can create the app and groups before touching DomainDash), then
   Part B in DomainDash, and finally paste the three SAML URLs that DomainDash shows on the saved connection into
   Entra's Basic SAML Configuration. DomainDash only shows those URLs once the connection has been saved, so
   expect to visit Entra twice.
3. Licensing note: assigning *groups* to an enterprise application needs Microsoft Entra ID P1 or higher in that
   tenant. On the Free tier you can only assign individual users, and the groups claim will not work as described.

## Part A. Create the Enterprise Application in Entra (staff tenant)

1. Sign in at <https://entra.microsoft.com>. Go to **Identity > Applications > Enterprise applications >
   New application > Create your own application**.
2. Name it `DomainDash`. Choose **Integrate any other application you don't find in the gallery (Non-gallery)**.
   Click **Create**.
3. Open **Properties**. Set **Assignment required?** to **Yes**. (Only assigned people can sign in.) Save.
4. Open **Users and groups > Add user/group**. Create (or reuse) security groups, for example
   `DD-Administrators`, `DD-Technicians`, and assign them to the app. Note each group's **Object ID**
   (Entra > Groups > the group > Overview). You will map these to roles in DomainDash.
5. Open **Single sign-on > SAML**.

### Basic SAML Configuration

Click **Edit** and fill in the three values shown in DomainDash on the connection page
("Values to paste into Microsoft Entra"):

| Entra field | DomainDash value |
|---|---|
| Identifier (Entity ID) | `https://<your-domain>/saml/<connection-id>/metadata` |
| Reply URL (Assertion Consumer Service URL) | `https://<your-domain>/saml/<connection-id>/acs` |
| Sign-on URL | `https://<your-domain>/saml/<connection-id>/login` |

Leave **Relay State** and **Logout URL** empty. Save.

The Sign-on URL matters: it makes the DomainDash tile in <https://myapps.microsoft.com> start the sign-in from
DomainDash. DomainDash refuses sign-ins that were not started from DomainDash (this blocks a class of replay and
forged-response attacks), so launching from other places that skip the Sign-on URL will fail.

### Attributes & Claims

Open **Attributes & Claims > Edit**. DomainDash needs these claims:

| Claim name (as sent) | What it is | Notes |
|---|---|---|
| `http://schemas.microsoft.com/identity/claims/objectidentifier` | the user's Entra Object ID | Sent by default. DomainDash uses this as the permanent identity. |
| `http://schemas.microsoft.com/identity/claims/tenantid` | the Entra tenant | Sent by default. Must equal the tenant ID saved on the connection. |
| `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress` | email | Default is `user.mail`. If your users have no `mail` value, edit this claim to use `user.userprincipalname`. |
| `http://schemas.microsoft.com/identity/claims/displayname` | display name | Optional, used for new accounts. |
| `http://schemas.microsoft.com/ws/2008/06/identity/claims/groups` | group Object IDs | Add it as below. |

Add the groups claim: **Add a group claim** > choose **Groups assigned to the application** (not "All groups")
> **Source attribute: Group ID** > Save. Use "Groups assigned to the application" on purpose: with "All groups",
a user in more than 150 groups gets no groups in the token ("overage"), and DomainDash will refuse the sign-in
with a message telling you to switch to this setting.

### SAML Certificates

* **Signing Option**: `Sign SAML assertion` (the default) or `Sign SAML response and assertion`. DomainDash requires
  a signed **assertion**. Signing Algorithm `SHA-256`.
* Copy the **App Federation Metadata Url**. You will paste it into DomainDash.
* Under **Notification Email** make sure a monitored mailbox is listed: Entra emails it before the certificate expires.

### Find your tenant ID

Entra > **Overview** > **Tenant ID** (a GUID). Or in PowerShell: `(Get-MgOrganization).Id` after
`Connect-MgGraph -Scopes Organization.Read.All`.

## Part B. Configure DomainDash

1. **Single Sign-On > Staff connection**.
2. **Name**: `Staff (Jargon)`.
3. **Allowed email domains**: your staff email domain(s), one per line. These route people from the login page and
   every sign-in must come from one of them.
4. **Entra tenant ID**: the GUID from above.
5. Paste the **App Federation Metadata URL**, tick **Fetch the metadata URL now**, and save. (Or paste the downloaded
   *Federation Metadata XML* into the XML box.) DomainDash fills in the entity ID, sign-on URL and certificate(s).
6. **Group to role mapping**, one per line, using the group Object IDs from step A4:

   ```
   11111111-1111-1111-1111-111111111111 = Administrator
   22222222-2222-2222-2222-222222222222 = Technician
   ```
7. **Default role**: leave on *None (deny)* so people in no mapped group cannot sign in.
8. **Trust Microsoft for MFA**: tick this only if Entra Conditional Access requires MFA for the DomainDash app
   (recommended: create a Conditional Access policy for the DomainDash enterprise app that requires MFA).
   When ticked, DomainDash does not ask for its own MFA for SSO sign-ins (including the Administrator
   mandatory-MFA rule). When not ticked, people who have DomainDash MFA set up are asked for their code after Microsoft.
9. Tick **Enabled** and save. Then use **Check configuration**, and **Test sign-in** in a private browser window.

If the new connection fails, nobody is locked out: password login still works until you turn on enforcement.

## Part C. Onboarding a client tenant

Each client signs in against *their own* Entra tenant through their own connection.

1. In DomainDash open the client (**Clients > Edit**) and click **Add SSO connection** (or
   Single Sign-On > Client connection). The connection is permanently bound to that client.
2. Ask the client's Entra administrator (or use delegated access, e.g. GDAP with an Application Administrator
   role) to do **Part A in their tenant**, using the values from the new connection's page. They send you:
   the **Tenant ID**, the **App Federation Metadata URL**, and the **Object ID of the group** whose members may use the portal.
3. In DomainDash: enter the client's email domain(s) (a domain can belong to one connection only, and a client
   cannot use your staff domain), the tenant ID, import the metadata, and map the client's group to the **Customer**
   role: `<group object id> = Customer`.
   DomainDash will refuse any staff role on a client connection.
4. Enable and test. New users from that tenant are created as Customers attached to that client only.

Existing portal users: if a client user already exists in DomainDash with the same email and is assigned to that
client only, their first Microsoft sign-in links to the existing account. DomainDash will never link an account that
belongs to a different client, a staff account, or an account with no client.

## Part D. Turning on "Enforce SSO for staff" and the break-glass account

1. Create (or pick) a local Administrator account that you will use only in emergencies. Give it a long random
   password and its own authenticator, and store both safely.
2. **Single Sign-On > Staff sign-in policy**: tick that administrator under *Break-glass administrators*.
   (Accounts already linked to SSO cannot be break-glass; break-glass accounts can never be linked to SSO.)
3. Confirm staff can sign in with Microsoft and that the break-glass account can still sign in with its password.
4. Tick **Enforce SSO for staff** and save. Staff (anyone who is not a pure customer) can no longer use passwords,
   except break-glass accounts. Customers are unaffected. DomainDash refuses to enable this without a break-glass
   admin and an enabled staff connection.

To undo it in an emergency: sign in with the break-glass account and untick the box, or in the database set the
`sso` row in the `settings` table, or with `php artisan tinker`:

```php
App\Models\Setting::put('sso', ['enforce_staff' => false]);
```

## Part E. Certificate rollover (do this before the 3-year expiry)

Entra signing certificates expire (default 3 years). A rollover with no downtime:

1. In Entra, SAML Certificates > **New Certificate**, give it an expiry, save (it is *inactive*).
2. In DomainDash open the connection and click **Refresh certificates from metadata URL**, or paste the new
   certificate into **Second signing certificate**. DomainDash now trusts both. Save.
3. In Entra, on the new certificate choose **Make certificate active**.
4. Test a sign-in. Then remove the old certificate in DomainDash (move the new one into the first box, clear the second) and,
   if you like, delete the old one in Entra.

**Check configuration** warns when a certificate expires within 30 days.

## Optional: Microsoft Graph PowerShell

The portal steps above are the supported path. These snippets automate the same things; run them first against a
test tenant. They use the Microsoft Graph PowerShell SDK (`Install-Module Microsoft.Graph`).

```powershell
Connect-MgGraph -Scopes "Application.ReadWrite.All","Directory.ReadWrite.All","AppRoleAssignment.ReadWrite.All","Group.Read.All"

# Tenant ID
(Get-MgOrganization).Id

# Object IDs of the groups you want to map
Get-MgGroup -Filter "startswith(displayName,'DD-')" | Select-Object DisplayName, Id

# Create the enterprise app from the non-gallery template (well-known template id) and switch it to SAML
$tpl = Invoke-MgGraphRequest -Method POST -Uri "https://graph.microsoft.com/v1.0/applicationTemplates/8adf8e6e-67b2-4cf2-a259-e3dc5476c621/instantiate" `
        -Body (@{ displayName = "DomainDash" } | ConvertTo-Json)
$sp  = $tpl.servicePrincipal
$app = $tpl.application
Update-MgServicePrincipal -ServicePrincipalId $sp.id -PreferredSingleSignOnMode "saml"

# Basic SAML configuration: paste YOUR values from the DomainDash connection page
$entityId = "https://portal.example.com/saml/<connection-id>/metadata"
$acs      = "https://portal.example.com/saml/<connection-id>/acs"
Update-MgApplication -ApplicationId $app.id -IdentifierUris @($entityId) -Web @{ RedirectUris = @($acs) }

# Emit only groups assigned to the application, as group IDs
Update-MgApplication -ApplicationId $app.id -GroupMembershipClaims "ApplicationGroup"

# Assign a group to the app (default access role)
$group = Get-MgGroup -Filter "displayName eq 'DD-Technicians'"
New-MgServicePrincipalAppRoleAssignedTo -ServicePrincipalId $sp.id -PrincipalId $group.Id -ResourceId $sp.id -AppRoleId "00000000-0000-0000-0000-000000000000"
```

Claim customisation (the `groups` claim name and format) and the Sign-on URL are quickest in the portal; check the
result under **Single sign-on > Attributes & Claims** after running the script.

## Troubleshooting

DomainDash shows a short message on the login page and writes the exact reason to **Audit Logs**
(action `sso.login.failed`, field `reason` in the context). Successful sign-ins are `sso.login.success`;
connection changes are `sso.connection.*`.

| Reason in audit log / message | Meaning and fix |
|---|---|
| `unknown_request` "attempt has expired or was not started here" | The sign-in took more than 10 minutes, was used twice, or was not started from DomainDash. Start again from the DomainDash login page or the app tile with a Sign-on URL. |
| `no_in_response_to` | IdP-initiated sign-in. Set the Sign-on URL in Entra (Part A) and launch from DomainDash. |
| `invalid_response` | Signature, audience, destination, issuer or timing failed. Check: certificate matches (Check configuration), Identifier and Reply URL in Entra equal the values on the connection page exactly, and the server clock is right (NTP). The audit `error` field names the failing check. |
| `expired` / `not_yet_valid` | Server clock is out by more than about a minute. Fix time sync. |
| `replay` | The same response was submitted twice. Normal if someone used the browser Back button. |
| `tenant_mismatch` | The person signed in with a different Entra tenant than the connection's tenant ID. |
| `domain_not_allowed` | Their email domain is not listed on the connection. |
| `no_group_match` "not a member of any group" | They are not in a mapped, application-assigned group. Add them to the group, wait a minute, retry. Their DomainDash roles were removed and sessions ended. |
| `groups_overage` | Too many groups for the token. Entra > the app > Attributes & Claims > Groups claim > **Groups assigned to the application**. |
| `no_object_id` / `no_email` | Claims missing. Re-check Attributes & Claims (Part A). |
| `wrong_client`, `staff_on_client_connection`, `client_user_on_staff_connection`, `already_linked`, `break_glass` | Safety rules stopped an account from being linked. Fix the user in DomainDash (assign the correct client or role) rather than loosening the connection. |
| `inactive` | The DomainDash user is disabled. |
| `jit_disabled` | Account creation is off and the person has no DomainDash account yet. |

Entra-side errors (shown on a Microsoft page, not DomainDash): `AADSTS50011` Reply URL mismatch (re-copy the Reply URL);
`AADSTS50105` user not assigned to the app (Users and groups); `AADSTS700016` Identifier not found in the tenant (re-copy the Identifier).

Other notes:

* Single logout is not implemented. Logging out of DomainDash ends the DomainDash session only.
* Behind a reverse proxy or load balancer, make sure Laravel trusts it (`X-Forwarded-Proto: https`), otherwise the
  generated URLs will be `http://` and Entra will reject them.
* Do not put a connection's SP values behind a login or IP allow-list: Microsoft's browser posts to the Reply URL
  directly, and this single URL is the only CSRF-exempt route (it is protected by the signed assertion instead).
* **Password-confirm protected actions need a local password.** Revealing a domain EPP / auth code or a hosting
  password asks the user to re-enter their DomainDash password. SSO-only staff have no local password, so they must set
  one first (use "Forgot your password?" on the login page to send themselves a reset link). Without it those reveals
  cannot be completed.
* **Changing `APP_URL` changes the Entra values.** The Identifier (Entity ID), Reply URL (ACS) and Sign-on URL are
  built from `APP_URL`. If the domain or scheme changes, update all of them in the Entra Enterprise Application,
  otherwise sign-in fails with `AADSTS50011` / `AADSTS700016`. In production the application refuses to run if
  `APP_URL` has no host.
* **MFA trust is opt-in for staff.** "Trust Microsoft for MFA" is off by default for staff connections. Only enable it
  after confirming a Conditional Access policy requires MFA for this application. SSO sessions are re-checked on every
  request: disabling a connection, unlinking a user, or switching the option off ends or tightens existing sessions
  immediately.
