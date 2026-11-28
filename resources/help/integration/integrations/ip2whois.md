---
title: IP2WHOIS (WHOIS lookups)
summary: Add an IP2WHOIS API key so {company} can fetch WHOIS data for domains the registrar cannot describe.
category: integrations
order: 60
audience: integration
related: [synergy-wholesale]
---

Some domains (for example certain country domains) are not described fully by the registrar API. {company} can fall back to IP2WHOIS to fill in WHOIS details such as nameservers and registration dates for those domains.

**Where:** **Admin > Settings > IP2WHOIS > Connection** (card "IP2WHOIS API").

## Get a key

Create an account at IP2Location.io and use the **IP2WHOIS** API key from your account. The page itself says "Get your key from IP2Location.io under IP2WHOIS". Plan limits and pricing are set by IP2Location, so check their site.

## Enter it

1. Open **Admin > Settings** and select the **IP2WHOIS** tile.
2. Paste the **API key** and select **Save API key**. It is stored encrypted and never displayed again.

## Run a WHOIS sync

1. On the same page select **Sync WHOIS for selected domains**.
2. Choose the domains in the dialog and start the sync.

You can also sync a single domain from **Domains > Manage**: open a domain's details panel and select **WHOIS sync**.

The WHOIS details then appear on each domain's **Overview** page, and nameserver information is filled in where the registrar had none.

## Troubleshooting

- **Nothing happens or an error appears:** check the key and your IP2Location plan's quota.
- **No data for a domain:** some registries don't publish WHOIS data. Check **Admin > Audit Logs > Integration Outbound** (provider IP2WHOIS) for the response.
