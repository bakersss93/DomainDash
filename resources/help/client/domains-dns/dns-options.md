---
title: "DNS options: DNS Hosting, forwarding, parking and custom nameservers"
summary: What each DNS mode does and how to switch a domain between them.
category: domains-dns
order: 40
audience: client
permission: dns.manage
related: [managing-dns-records, dns-propagation-and-mistakes]
---

The **DNS options** button on a domain's DNS page (shown only to accounts with the `dns.manage` permission) decides who runs the domain's DNS. Most people should leave it on **DNS Hosting**.

## The four modes

| Mode | Use it when |
|---|---|
| **DNS Hosting** | Your provider hosts your DNS. You add and edit records in the portal. This is the normal choice for websites and email. |
| **Custom nameservers** | Your DNS is run somewhere else (for example by a website builder or a CDN). You enter that service's nameservers. Records are then managed at that service, and the portal shows the nameservers but no editable record table. |
| **URL & Email Forwarding** | The domain uses the registrar's forwarding service. DomainDash switches the mode only. Set up any forwarding targets using your provider's own tools (check in your provider's portal or ask support). |
| **Parking** | The domain is held but not used for a website or email. |

## Change the mode

1. Open the domain's **Nameservers & DNS** page. See [Managing DNS records](/support/help/domains-dns/managing-dns-records).
2. Select **DNS options**.
3. Choose the **DNS mode**.
4. If you chose **Custom nameservers**, fill in up to four **Nameservers**, for example `ns1.example.net` and `ns2.example.net`.
5. Select **Save DNS options**.

The page then shows "DNS options updated for your-domain".

> **Warning:** Changing the mode can stop your website and email until the new setup is ready. Before switching away from **DNS Hosting**, copy down your existing records so you can recreate them. Before switching back to it, check the records are still there.

> **Note:** Records you add in the portal only work when the mode is **DNS Hosting**. If your changes are not showing up, check the **Current DNS mode** at the top of the page first.

## If saving fails

If the registrar cannot be updated, the portal saves your choice but shows a message that the update to the provider failed. Wait a minute and try again. If it keeps failing, [raise a ticket](/tickets/create) and quote the domain name and the time.

## How long until it works?

Nameserver changes can take from a few minutes up to a day or two to reach everyone. See [DNS changes: propagation and common mistakes](/support/help/domains-dns/dns-propagation-and-mistakes).
