---
title: "Vocus WSM: plans, ordering options and customer requests"
summary: Define plans and prices, choose realms and scopes, set order defaults and decide what customers may request.
category: integrations
order: 90
audience: integration
related: [vocus-wsm-setup, halopsa-billing, vocus-sandbox-and-certification]
---

All of these are under **Admin > Settings > Vocus Wholesale**.

## Plans tab

Select **Manage plans**. The window explains: one row per Vocus Plan ID (the PlanID sent to Vocus), with prices in AUD per month that can differ by technology. The cost and sell price are copied onto each service when it is ordered. Customers only ever see the sell price.

To add a plan fill in **Plan ID**, **Display name**, **Technology**, **Mapping**, **Speed tier**, **Download Mbps** and **Upload Mbps**, **Cost** and **Sell**, tick the **scopes** it applies to (none ticked means all), and keep **Active (offered on order forms)** ticked. Select **Add plan**.

- **Account mapping**: order and transfer forms offer only plans on this mapping. Plans with no mapping are always offered.
- **Import Vocus plan sheet**: upload the Vocus "RC Bundles PlanID" sheet (.xlsx or .csv, up to 2 MB), **Preview** the changes, then **Apply import**. Existing plans only get technology, mapping and speed updated.
- **Named tier speeds**: set the speeds for tiers Vocus names rather than numbers (for example Home Fast).
- **Halo item**: link each plan to a HaloPSA item (needed for [billing](/support/help/integrations/halopsa-billing)). One item may serve many plans.

Download and upload speeds are used to hide plans a copper line cannot support.

## Ordering tab

| Card | What it does |
|---|---|
| **PPPoE realms** | The NBN domain offered on order and transfer forms. The username sent to Vocus is `username@realm`. Add each realm (for example `wba.example.com.au`). Changes save immediately. |
| **Service scopes** | **Reseller-Connect** (Layer 3, hosted) and **Network-Connect** (Layer 2). Only enabled scopes appear on forms, and at least one must stay on. |
| **Order defaults** | Pre-selected values: **Authentication type (AuthType)**, **IP type (IPAssignment)** (both Reseller-Connect only), **NBN service level**, and the **NBN installation option** per technology. Each order can still change them. |
| **Customer requests** | See below. |

### Customer requests

Customers never order or cancel with the carrier. When you turn these on, their request is logged as a support ticket (Internet category, mapped in **Settings > HaloPSA > Tickets**) for staff to action.

- **Allow customers to request new internet services** needs the `internet.order` permission on the customer's role.
- **Allow customers to request cancellation** needs the `internet.cancel` permission, and customers can only request cancellation of their own services.

Select **Save customer requests**. The customer-side articles are [Requesting a new internet service](/support/help/services/request-new-internet-service) and [Requesting a cancellation](/support/help/services/request-internet-cancellation).

Also enable internet diagnostics per client with **Admin > Clients > Edit**, option "Allow this client's users to run internet diagnostics" (users also need `internet.diagnostics`).

## Service records tab

Lists internet service records that **need attention** (failed, unknown, expired or withdrawn transactions, or no Vocus service ID), or **All records**. Search by service ID, LOC ID or address.

**Remove** deletes the record from {company} only: nothing is sent to Vocus, so a live service is **not** cancelled (use **Cancel** on the service page for that). You must type the service ID to confirm. Warnings appear if the record shows ACTIVE or has a HaloPSA subscription or invoice line, since neither is removed elsewhere. Every removal is audit logged.

## Ordering a service (staff)

1. **Admin > Services > Internet > New Order** (starts at **Qualify**).
2. Complete the order form. Use **Transfer Service** for existing services.
3. Watch status on **Manage Internet**; {company} polls Vocus for the result.
