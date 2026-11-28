---
title: Managing DNS records
summary: View, add, edit and delete DNS records, and what each record type is for.
category: domains-dns
order: 20
audience: client
permission: dns.manage
related: [dns-examples, dns-options, dns-propagation-and-mistakes]
---

DNS records tell the internet where your website and email live. You manage them on the **Nameservers & DNS** page for each domain.

> **Warning:** A wrong DNS record can take your website or email offline. If you are not sure, [raise a ticket](/tickets/create) first, or write down the existing records before changing anything.

## Open the DNS page

1. In the left menu open **Domains**, then **Manage**.
2. Select your domain's row, then select **Nameservers & DNS**.

The page is called **DNS records for your-domain**. It shows the **Current DNS mode** and a table with these columns: **Hostname**, **Type**, **Content**, **TTL**, **Priority** and **Actions**.

You need the `dns.manage` permission to change records. Without it you can look at the page, but saving, adding or deleting is refused. Ask your provider if you need it.

## Add a record

1. Select **Add record**.
2. Fill in the form:
   - **Host**: the name the record applies to. Use `@` for the domain itself (for example `example.com`), or just the part in front, such as `www` or `mail`. Don't type the whole domain.
   - **Type**: choose from **A, AAAA, CNAME, MX, TXT, SRV, NS**.
   - **Content**: the value, such as an IP address or a hostname.
   - **TTL**: how long, in seconds, other computers may remember the answer. The form starts at 3600 (one hour). The smallest value is 60 and the largest the form accepts is 86400 (one day).
   - **Priority**: only used by MX (and SRV) records. Leave it at 0 for everything else.
3. Select **Save record**.

> **Note:** **Add record** is hidden when the domain uses **Custom nameservers**, because the records are kept at whoever runs those nameservers. See [DNS options](/support/help/domains-dns/dns-options).

## Edit a record

1. Change the **Hostname**, **Type**, **Content**, **TTL** or **Priority** directly in the record's row.
2. Select **Save** at the end of that row. Only that record is updated.

## Delete a record

1. Select **Delete** at the end of the record's row.
2. Confirm "Delete this DNS record?".

Select **Refresh records** at any time to reload the list from the DNS provider.

## Which type do I need?

| Type | What it does | Example content |
|---|---|---|
| **A** | Points a name at an IPv4 address. | `203.0.113.10` |
| **AAAA** | Points a name at an IPv6 address. | `2001:db8::10` |
| **CNAME** | Makes one name an alias of another name. A name with a CNAME cannot have other records. | `www` pointing to `example.com` |
| **MX** | Says which servers receive email for the domain. Lower priority numbers are tried first. | priority `10`, `mail.example.com` |
| **TXT** | Holds text used to prove ownership and to protect email (SPF, DKIM, DMARC). | `v=spf1 include:spf.protection.outlook.com -all` |
| **SRV** | Locates a specific service by name and port. The exact content format depends on the DNS provider. | Ask support before adding one. |
| **NS** | Names the nameservers for a name. Rarely needed. | `ns1.example.com` |

## Good habits

- Change one thing at a time, then check it.
- Keep the TTL at 3600 normally. Lower it to 300 a day before a planned change if you want faster switching, then raise it again afterwards.
- Never delete records you don't recognise. They may be for services you use, like email filtering.

See [DNS recipes](/support/help/domains-dns/dns-examples) for step-by-step examples and [DNS changes: propagation and common mistakes](/support/help/domains-dns/dns-propagation-and-mistakes) if something doesn't work.
