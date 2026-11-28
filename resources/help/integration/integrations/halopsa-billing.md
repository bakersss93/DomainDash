---
title: "HaloPSA billing: agreements, recurring invoice lines and subscriptions"
summary: How internet services are billed through a client's HaloPSA agreement and recurring invoice.
category: integrations
order: 40
audience: integration
related: [halopsa-connection, halopsa-sync-and-mappings, vocus-plans-and-ordering]
---

Billing is configured at **Admin > Settings > HaloPSA > Billing**. Each internet service bills through a HaloPSA agreement: {company} finds the client's agreement and its recurring invoice, then adds or updates one line on it.

> **Note:** {company} never creates agreements or recurring invoices. They must already exist in HaloPSA. Billing is currently written for internet services; the same settings are meant to serve domains, hosting and SSL later.

## Before you start

1. Complete the [HaloPSA connection](/support/help/integrations/halopsa-connection) and client mapping.
2. Link **every Vocus plan to a Halo item**: **Admin > Settings > Vocus Wholesale > Plans > Manage plans**, then open the Halo item picker from a plan row. See [Vocus plans](/support/help/integrations/vocus-plans-and-ordering).

## Agreements card

**Which recurring invoice to bill on** has two choices:

- **Find the client's agreement with the rule below**: {company} searches the client's Halo agreements.
- **Only the invoice chosen on each client**: you pick the recurring invoice per client.

A recurring invoice chosen on a *service* always wins. The one chosen on a *client* (**Admin > Clients > Edit**, once the client has a HaloPSA reference) is the fallback if the rule finds nothing usable.

The rule is **Contract Type + Agreement Subtype + Agreement Reference**:

- **Contract Type (optional)** and **Agreement Subtype (optional)**: pick from Halo's lists.
- **Agreement Reference matches (optional)**: plain text matches anywhere, `*` and `?` are wildcards. Placeholders such as `{plan}`, `{plan_id}`, `{client_code}`, `{service_id}`, `{username}` and `{avc_id}` are filled from the service. A client with several agreements bills each service on the one whose reference names it, for example `Connectivity - {plan} - {client_code}`.
- **Advanced: Halo lookup lists** holds the Halo lookup ids for Contract Types (28 by default) and Agreement Subtypes (76 by default). Change them only if your Halo uses different ids.

Select **Save agreement rule**.

## Recurring invoice lines card

This is the line added to the agreement's recurring invoice for each service: the plan's Halo item, the sell price, quantity 1 and the text you set.

1. Tick **Add or update the recurring invoice line**. When off, only subscriptions are synced.
2. **Description** (Halo `item_shortdescription`) and **Long description** (`item_longdescription`, one line per row). Leave empty for the defaults.
3. You can use the placeholders listed on the page. Halo's own variables such as `$PERIODSTARTDATE` and `$PERIODENDDATE` are sent unchanged and filled in by Halo when it invoices.
4. Select **Save line settings**.

## Subscriptions card

Tick **Sync subscriptions** to create or update a HaloPSA subscription for each service on the client, attached to the recurring invoice line. It is **off by default**, so nothing is pushed until you turn it on. Select **Save subscription setting**.

## Preview before you sync

The **Preview** card shows the line text and the agreement a chosen service would bill on, using the values on the page even if you haven't saved them. Nothing is sent to Halo. Use it on a few services first.

## Running it

- Per service: **Admin > Services > Internet > Manage Internet**, open a service's **Halo billing** tile (it jumps to the Halo billing card on the service page).
- Everything at once: **Sync to HaloPSA** on **Admin > Services > Internet > Manage Internet** (needs the `sync.run` permission). Unchanged services are skipped.

## Troubleshooting

- **No agreement found:** loosen the rule (remove the reference) or choose the invoice on the client.
- **No Halo item:** link the plan to a Halo item first.
- **A client has several agreements:** make the Agreement Reference name the plan or service, as above.
