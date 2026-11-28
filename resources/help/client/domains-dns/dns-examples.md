---
title: "DNS recipes: website, Microsoft 365, Google Workspace, DMARC and verification"
summary: Worked examples for the records most people need, using example.com and contoso.com.
category: domains-dns
order: 30
audience: client
permission: dns.manage
related: [managing-dns-records, dns-propagation-and-mistakes, dns-options]
---

Each recipe uses the **Add record** form described in [Managing DNS records](/support/help/domains-dns/managing-dns-records). The names `example.com` and `contoso.com` are examples: use your own domain, and copy long values exactly as your service provider shows them.

> **Warning:** These recipes only take effect when the domain's **Current DNS mode** is **DNS Hosting**. If it shows **Custom nameservers**, the records must be changed wherever those nameservers are managed.

## Point your website at a web server

You have an IP address from your web host (here `203.0.113.10`).

1. **Add record** with **Host** `@`, **Type** `A`, **Content** `203.0.113.10`, **TTL** `3600`, **Save record**.
2. **Add record** with **Host** `www`, **Type** `CNAME`, **Content** `example.com`, **TTL** `3600`, **Save record**.

If your host gave you an IPv6 address too, add the same `@` record again with **Type** `AAAA`.

> **Tip:** If the `@` or `www` name already has a record, edit it instead of adding a second one. A name with a CNAME cannot have an A record as well.

## Microsoft 365 email

Microsoft shows the exact values in the Microsoft 365 admin centre under **Settings > Domains** when you add your domain. These are the usual records for `contoso.com`:

| Host | Type | Content | Priority |
|---|---|---|---|
| `@` | MX | `contoso-com.mail.protection.outlook.com` | 0 |
| `@` | TXT | `v=spf1 include:spf.protection.outlook.com -all` | 0 |
| `autodiscover` | CNAME | `autodiscover.outlook.com` | 0 |

Add each row with **Add record**. Put the number in the **Priority** box for the MX record and leave it at 0 for the others.

### DKIM (signing) for Microsoft 365

1. In the Microsoft Defender portal, open the DKIM settings for your domain. It shows two CNAME records.
2. Add two CNAME records: **Host** `selector1._domainkey` and `selector2._domainkey`. The **Content** of each is the long name Microsoft shows you (it ends in `onmicrosoft.com`). Copy it exactly.
3. Go back to Microsoft and switch DKIM signing on.

## Google Workspace email

Google shows the values in its Admin console when you set up Gmail.

| Host | Type | Content | Priority |
|---|---|---|---|
| `@` | MX | `smtp.google.com` | 1 |
| `@` | TXT | `v=spf1 include:_spf.google.com ~all` | 0 |

Older Google setups use five MX records (`ASPMX.L.GOOGLE.COM` priority 1, then four alternates). If Google's own instructions show those, follow Google's instructions.

For DKIM, Google gives you a TXT record: **Host** `google._domainkey`, **Type** `TXT`, **Content** the long `v=DKIM1; k=rsa; p=...` value from the Admin console. Add it, then start authentication in Google.

> **Warning:** A domain may only have **one** SPF record (a TXT record starting `v=spf1`). If you use more than one email service, combine them into a single record, for example `v=spf1 include:spf.protection.outlook.com include:_spf.google.com -all`. Two SPF records break SPF for both.

> **Important:** Before you replace old MX records with new ones, make sure your new mailboxes exist. Mail sent while records point to the wrong place can be lost.

## DMARC

DMARC tells receiving mail servers what to do with email that fails SPF and DKIM checks.

1. **Add record** with **Host** `_dmarc`, **Type** `TXT`.
2. **Content**: `v=DMARC1; p=none; rua=mailto:dmarc-reports@example.com`
3. **Save record**.

Start with `p=none`, which only collects reports. Once you have checked the reports and your email is passing, move to `p=quarantine`, and later `p=reject`. Use a real mailbox you can read in place of `dmarc-reports@example.com`.

## Domain verification (TXT)

Many services (Microsoft 365, Google, Adobe, and others) ask you to prove you own the domain by adding a TXT record.

1. Copy the exact value the service gives you, for example `MS=ms12345678` or `google-site-verification=AbC123...`.
2. **Add record** with **Host** `@`, **Type** `TXT`, **Content** that value, **Save record**.
3. Return to the service and select **Verify**. It can take a few minutes. See [propagation](/support/help/domains-dns/dns-propagation-and-mistakes).

A domain can have several TXT records on `@` at once (verification, SPF and so on). Add a new one rather than replacing the others.

## Where do the values come from?

The values for Microsoft 365 and Google Workspace are published by those companies and can change. Always compare with what their admin portal shows you. If a value in your admin portal differs from this article, trust the portal.
