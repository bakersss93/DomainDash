---
title: Synergy Wholesale (domains, DNS, hosting and SSL)
summary: Connect the registrar API so domains, DNS, hosting services and SSL certificates sync into {company}.
category: integrations
order: 10
audience: integration
related: [platform-https, sync-scheduler, ip2whois]
---

Synergy Wholesale is the registrar and reseller platform behind {company}'s domains, DNS hosting, web hosting and SSL certificates. One connection covers all four.

**Where:** **Admin > Settings > Synergy Wholesale > Connection** (card "Synergy Wholesale API").

## What you need from Synergy

Sign in to the Synergy Wholesale reseller portal and find:

1. Your **Reseller ID**.
2. An **API key** for your reseller account.
3. If your account restricts API access by IP address, add this server's public IP address to the allowed list.

> **Note:** The exact menu names for the API key and the IP allow-list are in Synergy's reseller portal, and they may change. Check in your provider's portal (Synergy's own help is the authority). These steps could not be verified from inside {company}.

## Enter it in {company}

1. Open **Admin > Settings** and select the **Synergy Wholesale** tile.
2. Enter the **Reseller ID**.
3. Enter the **API key**. It is stored encrypted and never shown again. To replace it later, type a new key. Leave the box showing the placeholder to keep the stored key.
4. Leave **WSDL path or URL** blank unless Synergy tells you otherwise. {company} ships with a bundled copy. If you do set it, it must be a `synergywholesale.com` HTTPS address or a file inside the application. Anything else is rejected.
5. Select **Save credentials**.

The tile on the Settings home shows **Configured** once an API key is stored.

## What it is used for

| Feature | Where you use it |
|---|---|
| Domain list and expiry | **Domains > Manage**, button **Bulk domain sync**. Also scheduled: see [Scheduler](/support/help/platform/sync-scheduler). |
| DNS records and nameservers | Customers and staff manage records from a domain's **Nameservers & DNS** page. Changes go straight to Synergy. |
| Hosting services | **Services > Hosting Services > Manage Hosting**, button **Sync services**. |
| SSL certificates | **Services > SSLs > Manage SSLs**, button **Sync from Synergy**. |
| Let's Encrypt for the portal itself | **Settings > Platform HTTPS**. A DNS TXT record is created in Synergy. See [Platform HTTPS](/support/help/platform/platform-https). |

Domains the registrar cannot describe (for example some country domains) can fall back to [IP2WHOIS](/support/help/integrations/ip2whois).

## Check it works

1. Select **Bulk domain sync** on **Domains > Manage**. Domains appear with status and expiry.
2. Look at **Admin > Audit Logs > Integration Outbound** and filter the provider to Synergy. Successful calls are green, failures show the error. See [Audit logs](/support/help/security/audit-logs-and-retention).

Sync buttons queue a background job, so a queue worker must be running. See [Scheduler and sync jobs](/support/help/platform/sync-scheduler).

## Troubleshooting

- **Authentication or "API key" errors:** re-enter the key and check the Reseller ID has no spaces. Confirm the server's IP is allowed in Synergy.
- **Settings saved but domains don't appear:** sync is queued. Check the worker banner on the settings page.
- **"The WSDL must be a synergywholesale.com HTTPS URL":** clear the field to use the bundled copy.

> **Warning:** Rotate the API key in Synergy if it is ever exposed, then enter the new key here. Entering it here replaces the stored one immediately.
