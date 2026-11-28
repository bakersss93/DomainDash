---
title: "HaloPSA: client mapping, asset sync and ticket mappings"
summary: Link {company} clients to Halo clients, choose how items become Halo assets, and map ticket types and statuses.
category: integrations
order: 30
audience: integration
related: [halopsa-connection, halopsa-billing, sync-scheduler]
---

Do the [connection](/support/help/integrations/halopsa-connection) first. All of this is under **Admin > Settings > HaloPSA**, one tab per topic.

## Clients tab: which client is which

1. Open the **Clients** tab. The card is called **Client mappings**.
2. If the Halo list is empty or stale, select **Refresh from Halo**. Halo clients come from a local mirror that the refresh updates. A background worker must be running.
3. Select **Add**. Choose the **DomainDash client** and the **HaloPSA client** (type to search), and save.

Saving a mapping queues an asset refresh for that client. You can also link a client from **Admin > Clients > Edit** with **Select from HaloPSA** under **HaloPSA Reference**.

A client that is not linked cannot raise tickets: customers see "This client is not linked to HaloPSA yet."

## Assets tab: documenting services as Halo assets

The **Asset mappings** card has one row per {company} item type: domains, internet services, hosting services and SSL certificates.

1. Select a row to edit it.
2. Tick **Sync this item type to HaloPSA** to turn it on. Scheduled and manual syncs only push item types that are switched on.
3. Pick the **Halo asset type** where its assets are created (asset types are read live from Halo and cached for 10 minutes, with a refresh option). Domains without a choice use the asset type named *Domain*.
4. Under **DomainDash value to Halo field**, choose which Halo field receives each value, or **Do not sync this value**. **Suggest from Halo** fills sensible guesses by field name.
5. Save.

> **Warning:** Changing an asset type or its field mapping re-pushes every record of that item type on the next sync (about one Halo call per record, rate limited).

Use the **Test mapping** card to preview what would be sent for one domain. Nothing is sent to Halo.

Internet services are still billed through Halo subscriptions or recurring invoices (see [billing](/support/help/integrations/halopsa-billing)); the asset mapping only writes asset records.

## Tickets tab: types and statuses

**Ticket type mappings** decide which Halo ticket type {company} uses for each *service category* when it creates, syncs or lists tickets.

1. Select **Add**.
2. **Service category**: pick one or type a new one. The portal's ticket form uses Domain, Web Hosting and SSL, and internet requests from customers use the Internet category.
3. **Halo ticket type**: the type in Halo.
4. **Ticket class**: **Support/Issue** or **Service Request**.

If a category and ticket type has no mapping, customers get "No Halo ticket type mapping is configured for this service category and ticket type." Customers also only see tickets whose Halo type is mapped here.

**Status mappings** (button **Edit status mappings**) translate Halo ticket statuses into the labels shown in the Support Requests table: pick a **DomainDash status** (for example Open, In Progress, Closed) and the matching **Halo status**.

## Sync tab: pushing and refreshing

- **Domain assets**: **Sync domains to Halo** opens a dialog to choose which domains to push.
- One card per enabled item type: **Sync to Halo**. Unchanged records are skipped.
- **Refresh from HaloPSA**: **Refresh now** queues a refresh of the local Halo mirror (clients and domain assets).
- **Schedule**: open the [sync scheduler](/support/help/platform/sync-scheduler) to run these on a timer.

Progress and results appear live under the cards. Nothing syncs unless a queue worker is running: a warning banner says so when jobs are waiting.

## Troubleshooting

- **A list is empty:** save the connection first, then refresh.
- **Push failed:** the run shows the (sanitised) reason from Halo. See **Admin > Audit Logs > Jobs & Schedules** and **Integration Outbound**.
- **Customers see no tickets:** check the client link, the Tickets mapping and that HaloPSA is configured.
