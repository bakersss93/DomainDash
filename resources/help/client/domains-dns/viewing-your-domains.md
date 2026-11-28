---
title: Viewing your domains
summary: Find your domains, check expiry dates and open the overview and DNS pages.
category: domains-dns
order: 10
audience: client
permission: domains.view
related: [managing-dns-records, dns-options]
---

The portal lists every domain that belongs to your organisation. You only ever see domains assigned to the client accounts you belong to.

## Open the domain list

1. In the left menu, open **Domains**, then select **Manage**.
2. Use the **Search domains...** box and **Search** to find a domain by name.
3. Select a column heading (**Domain Name**, **Status**, **Expiry** or **DNS Configuration**) to sort. Select it again to reverse the order.

The list shows:

| Column | What it means |
|---|---|
| **Domain Name** | The domain, for example `example.com`. |
| **Status** | The domain's status at the registrar, such as Active. |
| **Expiry** | Days left until the domain expires. It turns red when 30 days or fewer remain. |
| **DNS Configuration** | How the domain's DNS is run: **DNS Hosting**, **Custom Nameservers**, **URL & Email Forwarding** or **Domain Parking**. |
| **Client** | Which of your organisations the domain belongs to. |

The **Dashboard** also shows your domains in a **Domains** table with their expiry dates, and counts how many are expiring soon.

## Open a domain

Select a row to open its details panel. For customer accounts the useful tiles are:

- **Overview**: the full details page.
- **Nameservers & DNS**: the DNS records page. See [Managing DNS records](/support/help/domains-dns/managing-dns-records).

Staff-only tools (such as **Renew**, **Assign client**, **Initiate transfer**, **Transactions**, **WHOIS sync** and **Delete domain**) and the **Bulk domain sync** button are not shown on customer accounts. The same applies to the assign-client icon in the list and the **Client / categories** box on the overview page.

## The overview page

**Domain overview** shows:

- **Domain information**: Domain name, Status, Expiry date, Auto renewal (Enabled or Disabled) and DNS configuration.
- **Nameserver information**: the nameservers the domain currently uses.
- **WHOIS** details, when your provider has synced them.

> **Note:** The **Get auth code** row (the transfer or EPP code) only appears for accounts that have been given permission to release it, which is normally your provider's staff, not customer accounts. If you are moving a domain away, [raise a ticket](/tickets/create) and ask for the transfer code.

## Renewing a domain

Renewals are handled by your provider. If a domain is close to expiry and you want to keep it, [raise a ticket](/tickets/create) choosing **Domain** as the service category, or contact your provider.

> **Warning:** If a domain expires, your website and email on that domain stop working. Check the **Expiry** column regularly.
