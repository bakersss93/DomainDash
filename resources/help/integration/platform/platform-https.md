---
title: HTTPS certificate for the portal (Platform HTTPS)
summary: Serve {company} over HTTPS with Let's Encrypt (DNS-01 via Synergy) or an uploaded certificate, and force HTTPS.
category: platform
order: 30
audience: integration
related: [synergy-wholesale, sync-scheduler]
---

**Where:** **Admin > Settings > Platform HTTPS** (a page of its own, with a **Back to settings** button).

Neither Microsoft sign-in nor secure cookies work on plain http, so set the portal up on its real https address (`APP_URL`, currently `{app_url}`) first.

## One-time server setup

The portal itself never runs certbot or edits nginx. A small root helper on the server does that. If the page shows **HTTPS helper not installed or not running**, an administrator with server access runs, as root:

```bash
sudo bash deploy/https/install.sh
systemctl status domaindash-certctl.timer
```

and adds the HTTPS server block from `deploy/https/nginx-domaindash.conf.example`. The full runbook is in the project README under "Platform HTTPS". Requests stay *pending* until the helper runs.

## Get a certificate

### Option A: Let's Encrypt (DNS-01 through Synergy)

Validated with a TXT record created in Synergy. **Port 80 is not needed** and wildcards work. The domain must exist in {company} and Synergy.

1. In the **Let's Encrypt** card check **Hostname** (taken from `APP_URL`).
2. Enter a **Contact e-mail** (Let's Encrypt sends expiry notices there).
3. Tick **Use the Let's Encrypt staging environment** only for testing, because browsers don't trust staging certificates.
4. Select **Request certificate**. Watch **Request history** for status (Pending, Running, Succeeded or Failed) and the log.

### Option B: upload a certificate

Use this when your DNS is not on Synergy or you buy certificates elsewhere.

1. In the **Upload certificate** card enter the **Hostname the certificate must cover**.
2. Choose **Format**: **PEM** (certificate, private key without passphrase, optional chain) or **PFX / PKCS#12** (file plus password).
3. Select **Validate and install**.

The certificate, key match, expiry and names are validated here and again by the helper. The private key is stored encrypted and installed root-only.

## Force HTTPS

When a valid, unexpired certificate is active, the **Force HTTPS** card lets you redirect http to https and send HSTS:

1. Open the portal over **https://** in another tab and confirm it loads without warnings.
2. Tick **I have opened the dashboard over https:// and it loads without warnings** and select **Enable Force HTTPS**.

> **Warning:** Only enable Force HTTPS after you've proved https works. If you lock yourself out, switch it off on the server (see the README break-glass notes).

## Renewal and alerts

- Let's Encrypt certificates renew automatically twice a day. Uploaded certificates do not: upload a new one before expiry (**Auto-renew** shows Off).
- The **Current certificate** card shows hostname, expiry (days left), source, issuer and names.
- A daily check raises alerts at 30, 14, 7 and 1 days before expiry and when renewal fails: an audit entry, an in-app notification for administrators and, if configured, the email templates under **Admin > Email Templates**.

## Troubleshooting

- **Stays pending:** the helper is not running.
- **DNS validation fails:** check the Synergy connection and that the domain is in your Synergy account. Port 80 is not needed. The log in **Request history** shows the reason.
- **nginx test failed:** the helper restores the previous configuration. Run `nginx -t` on the server and fix the conflicting directive.
- **Behind a proxy or load balancer:** set `TRUSTED_PROXIES` so the portal can tell the request was secure.
