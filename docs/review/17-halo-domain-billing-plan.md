# 17 - Domain (and hosting / SSL) billing in HaloPSA: design only

Status: planning. Nothing here is implemented. It reuses the mechanism built for internet services (`16-halo-subscriptions-swagger-facts.md`): Halo Item, subscription (`/SoftwareLicence`), recurring invoice line (`/RecurringInvoice/Lines`), one recurring invoice per client.

## 1. Today

- Domains are pushed to Halo as **assets** (`HaloDomainPusher`, `PushDomainsToHaloJob`): type, custom fields, expiry, DNS notes, `halo_asset_id`, `halo_sync_hash`. No money is involved.
- `DomainPricing` already holds a sell price per TLD (admin Domains → Pricing) and Synergy gives cost. Domains have `expiry_date`, `auto_renew`, client, TLD.
- Hosting (`HostingService`) and SSL (`SslCertificate`) exist as services with expiry dates; same shape.

## 2. Mapping model

| DomainDash | Halo |
|---|---|
| TLD (`.com.au`, `.com`, `.net`...) | one Item per TLD, or one "Domain registration" Item per price band |
| hosting plan / SSL product | one Item per plan / product |
| domain | subscription (quantity 1, start = registration or last renewal date, end = expiry) **or** a quantity on a shared line |
| client | recurring invoice (same picker / contract strategy as internet) |

Link UI: the same Halo item picker modal on Domains → Pricing rows (per TLD) and on hosting plan / SSL product settings. Store `halo_item_id` / `halo_item_name` beside the existing sell price.

## 3. Options

**A. One subscription per domain, one line per domain (mirror of internet).** Exact, easy to cancel one domain, clutter on the invoice with 40 lines for a client with 40 domains. Reference = domain name in the subscription notes marker (the same `[[DOMAINDASH-SVC:..]]` idea: `[[DOMAINDASH-DOM:example.com]]`).

**B. One subscription per domain, one line per TLD with quantity from subscriptions.** Halo's line can take its quantity from linked subscriptions (`quantity_subscriptions`, `recurring_invoice_quantity_type`). Tidy invoice ("3 x .com.au"), price follows the item. Needs the tenant's quantity-type id (open question 3 in doc 16) and per-domain prices are not possible (all domains of a TLD cost the same).

**C. Annual (renewal based) billing.** Domains renew yearly while recurring invoices are usually monthly. Choices: (i) line billed annually (`period` on the invoice or line, `startdate` = renewal date); (ii) monthly line with 1/12 price; (iii) no recurring line, create a one-off invoice/sales line at each renewal. Recommendation: (i), invoice with an annual period per client ("Domains & hosting - annual"), line `startdate` moved to the new renewal date and `enddate` set on non-renewal.

**D. Keep assets only** and invoice domains manually or from the registrar statement. Zero work, zero risk.

## 4. Interaction with the asset push

- Asset stays (support/inventory). Billing is additive and independent, enabled by its own setting.
- Add `halo_subscription_id`, `halo_recurring_line_id`, `halo_billing_hash`, `halo_billing_error` to `domains` (+ hosting, ssl); the asset hash stays untouched so a price change never re-pushes the asset.
- Triggers: domain registered/transferred in, renewal (expiry date moves forward), auto-renew toggled off, domain deleted/expired -> end date. Debounced job `PushDomainBillingToHaloJob` with a SyncRun scope `halo/domain-billing`, reusing `HaloSubscriptionPusher` (generalised to a "billable item" interface: name, reference marker, item id, price, cost, start, end, quantity).
- Link the subscription to the asset? The licence schema has `device_child_id` / `configuration_items` fields; linking subscription to the domain asset is possible but is optional and not planned.

## 5. Risks

- Billing from DomainDash means a wrong price becomes a wrong invoice: keep "sync" opt-in per client, show a dry-run preview (what would be created) before the first push.
- Synergy cost vs Halo item cost can disagree; decide which wins (proposal: DomainDash `DomainPricing` wins, sent on the line).
- Items with `update_recurring_invoice_price` set in Halo overwrite line prices.
- Domains with no client or Halo client link must be skipped with a clear error (same as assets).

## 6. Questions for you

1. Do you bill domains/hosting/SSL through Halo recurring invoices at all, or only record them as assets?
2. Annual or monthly? One annual invoice per client for domains, or merged into the monthly one?
3. One Halo item per TLD (suggested) or a single "Domain" item with the price on the line?
4. One line per domain (A) or grouped per TLD with a quantity (B)? Does your tenant have the "quantity from subscriptions" setting (give us one example line so we can read its `recurring_invoice_quantity_type`)?
5. Which price wins: DomainDash sell price per TLD, or the Halo item price?
6. When a domain lapses or is cancelled: end date on the line (our default), or deactivate the subscription and leave the line?
7. Same rules for hosting and SSL (items per plan), or later?
8. Should DomainDash be allowed to create the client's recurring invoice if none exists (swagger supports `POST /RecurringInvoice`)? Default stays off.
