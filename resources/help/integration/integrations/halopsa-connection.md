---
title: HaloPSA connection
summary: Create an API application in HaloPSA and enter its details so tickets, clients and assets can sync.
category: integrations
order: 20
audience: integration
related: [halopsa-sync-and-mappings, halopsa-billing, it-glue]
---

HaloPSA powers customer tickets and (optionally) billing in {company}. Customers raise and read tickets in the portal, and the tickets live in HaloPSA.

**Where:** **Admin > Settings > HaloPSA > Connection** (card "HaloPSA API").

> **Note:** Save the connection first. The other HaloPSA tabs (Clients, Assets, Tickets, Billing, Sync) load their lists using the *saved* credentials.

## 1. Create the API application in HaloPSA

In HaloPSA open **Configuration > Integrations > Halo API** (the description on the {company} page gives this same path). There you can:

1. Open **View Applications** and create a new application, for example named `{company}`.
2. Choose the authentication method that gives you a **Client ID** and **Client Secret** for a service (client credentials) connection.
3. Make sure the application is allowed to read and write what {company} uses: clients, tickets and ticket actions, assets and asset types, and, if you use billing, contracts, recurring invoices and items.
4. Copy the **Client ID** and **Client Secret**.
5. On the same Halo API page note the **Resource Server** and **Authorisation Server** addresses.

> **Note:** Halo's menus and the way permissions are granted to an API application differ between Halo versions and hosted/self-hosted tenants. These steps could not be verified from inside {company}: check in your provider's portal (Halo's own documentation) for the current screens. {company} requests a token using the client-credentials grant with scope `all`, so the application must allow that.

## 2. Enter it in {company}

| Field | What to enter |
|---|---|
| **Resource server URL** | Halo's API address, for example `https://yourtenant.halopsa.com/api`. Must be a public https address. |
| **Authorisation server URL** | Optional. Halo's auth address, for example `https://yourtenant.halopsa.com/auth`. Leave blank to derive it from the resource server. |
| **Tenant** | Only for the hosted HaloPSA cloud. Your tenant name, for example `yourtenant`. Leave blank otherwise. |
| **Client ID** | From the application. |
| **API key (client secret)** | From the application. Stored encrypted and never shown. Type a new one to replace it. |

Select **Save connection**. The tile on **Admin > Settings** shows **Configured** once the URL and Client ID are set.

> **Warning:** URLs that point to private or internal addresses are refused for safety. Use the public address of your Halo tenant.

## 3. Check it

1. Open the **Clients** tab. If the list is empty select **Refresh from Halo** and wait for the run to finish.
2. Open the **Tickets** tab. The ticket type and status pickers should load Halo's lists (cached for 10 minutes).

If the lists show "Save the HaloPSA connection first" or fail to load, re-check the URL, tenant and credentials, then look in **Admin > Audit Logs > Integration Outbound** (provider HaloPSA) for the error.

## Next steps

1. [Map clients, assets and tickets](/support/help/integrations/halopsa-sync-and-mappings).
2. If you bill through Halo, set up [billing](/support/help/integrations/halopsa-billing).
3. Customers can only raise tickets for clients that are linked to HaloPSA (**Admin > Clients > Edit > HaloPSA Reference**, or the Clients mapping tab).
