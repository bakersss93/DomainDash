---
title: Email (SMTP) settings
summary: Configure the outgoing mail server used for notifications, invitations and password resets, and send a test.
category: platform
order: 10
audience: integration
related: [backups, users-roles-permissions-mfa]
---

{company} sends password-reset emails, invitations, expiry reminders and other notifications through the SMTP server you configure here.

**Where:** **Admin > Settings > Email (SMTP) > SMTP**.

## Fields

| Field | What to enter |
|---|---|
| **Host** | Your mail server, for example `smtp.example.com`. Must be a public hostname or IP. |
| **Port** | 587 (TLS), 465 (SSL) or 25 (unencrypted). |
| **Encryption** | **None**, **TLS** or **SSL**. Match the port: 587 normally uses TLS, 465 uses SSL. |
| **Username** / **Password** | If your server needs them. The password is stored encrypted and never shown. |
| **From address** | The sender, for example `noreply@example.com`. Use an address your mail server is allowed to send as. |
| **From name** | The display name, for example `{company}`. |

Select **Save SMTP settings**.

> **Note:** For safety, private or internal addresses (for example `10.x.x.x`) are rejected as the host. If you deliberately use an internal relay, an administrator must allow private hosts in the server configuration (`DOMAINDASH_ALLOW_PRIVATE_HOSTS=true`).

## Send a test

1. Save first.
2. In **Send a test email** enter a **Test recipient** and select **Send test email**.
3. Check the message arrives (and check spam).

## Examples

- A generic provider: host `smtp.example.com`, port `587`, encryption **TLS**, the username and password your provider gave you, from address `noreply@example.com`.
- Microsoft 365: the SMTP host, port and the "SMTP AUTH" setting for the sending mailbox are controlled in your Microsoft 365 admin centre. It is often disabled by default for new tenants. Check Microsoft's current documentation, or use a dedicated mail relay.

## Good to know

- In production, password-reset, invitation and verification email is **refused** while the mail driver is `log` (so reset links never end up in log files). Configure SMTP here and the problem goes away.
- Customer-facing reminders (for example domain or SSL expiry) use templates under **Admin > Email Templates**, where you also choose the audience and admin recipient.
- Add SPF, DKIM and DMARC records for the sending domain so messages are not treated as spam. See the customer guide [DNS recipes](/support/help/domains-dns/dns-examples) for the record formats (it is visible to anyone who may manage DNS).

## Troubleshooting

- **Test fails with a connection error:** check host, port and encryption, and that the server's firewall allows outbound connections on that port.
- **"Authentication failed":** re-enter the username and password.
- **Test works but users get no mail:** check spam, SPF/DKIM, and the from address.
