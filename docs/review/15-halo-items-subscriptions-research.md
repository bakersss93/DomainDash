# 15 - HaloPSA Items and Subscriptions research (Vocus plan linking + subscription sync)

Status: research only. Date 2026-11-24. No code changed.

## 0. Evidence limits (read first)

- The official swagger `https://halo.haloservicedesk.com/api/swagger/v2/swagger.json` returned **HTTP 500** (curl and WebFetch, repeated); `/api/swagger/index.html` returns 200 (714 bytes, a shell page); `/swagger/v1/swagger.json` is 404. So **no schema field lists could be verified from swagger**. The swagger is served per tenant: it must be read from the real tenant at `https://<tenant>/api/swagger` (Huntress feedback thread notes this too).
- The raw GitHub paths for HaloAPI are `Public/Get|New|Set/...`; PowerShell Gallery mirrors work and were used.
- Halo's own guides on the Subscriptions tab were not retrievable (usehalo.com FAQ 404; search only returned titles).

## 1. Verified facts

| Fact | Source |
|---|---|
| `GET /api/Item` list params (module `Get-HaloItem`): `search`, `count`, `pageinate`, `page_size`, `page_no`, `order`/`orderdesc` (up to 5 levels), `includedetails`, `includeinactive`, and single-item by id. | https://raw.githubusercontent.com/homotechsual/HaloAPI/main/Public/Get/Get-HaloItem.ps1 |
| Licences/subscriptions endpoint is **`/api/SoftwareLicence`**. Create = POST array of objects (module posts to endpoint `SoftwareLicence`). | https://www.powershellgallery.com/packages/HaloAPI/1.23.1/Content/New%5CNew-HaloSoftwareLicence.ps1 |
| `Get-HaloSoftwareLicence` params: licence id, `client_id`, `count`, `pageinate`, `page_size`, `page_no`, `order`, `orderdesc`, `includedetails`, `includeinactive`. | https://raw.githubusercontent.com/homotechsual/HaloAPI/main/Public/Get/Get-HaloSoftwareLicence.ps1 |
| `Set-HaloSoftwareLicence` requires licence `id` and a client id and checks the licence exists before update (update = POST of object containing `id`). | https://www.powershellgallery.com/packages/HaloAPI/1.23.2/Content/Set%5CSet-HaloSoftwareLicence.ps1 |
| Halo calls the feature "Licences/Subscriptions"; it is a client-level list with quantity and cost. Subscription must then be **attached to a recurring invoice** (the contract's Recurring Invoices tab) for billing; third-party integrations (INFIMA) state they do not add subscriptions to invoices automatically and the operator does it manually. Recurring invoice lines can be made inactive when licences go inactive (Sherweb). | https://feedback.huntress.com/integrations/p/halopsa-billing-integration ; https://kb.infimasec.com/docs/integration-halopsa ; https://helpdesk.sherweb.com/en-US/knowledge-base/articles/KA-05030 ; https://usehalo.com/faq-list/recurring-invoices-billing (title only) |
| Existing client code: list endpoints are paginated with `pageinate=true`, `page_size`, `page_no`, response has `record_count` plus a list key. | `app/Services/Halo/HaloPsaClient.php` `paginate()` |

## 2. Unverified (must confirm on the live tenant swagger / a test call)

1. Item response fields. Expected (from Halo conventions, NOT verified): `id`, `name`, `description`, `baseprice`, `costprice`, `recurringprice`? , `isrecurring`?, `itemgroup_id`/`itemgroup_name`, `inactive`, `accountsid`. Plan: log one `GET /api/Item?count=1` and inspect.
2. Response envelope of `/api/Item` without pagination (likely `{record_count, items:[...]}` ; `extractList` already handles wrapper keys and `data`/`Results`).
3. `SoftwareLicence` fields. Expected (unverified): `id`, `client_id`, `name`/`licence_name`?, `item_id`, `contract_id`, `recurringinvoice_id`/`ri_id`?, `count` (quantity), `price`, `cost`, `start_date`, `end_date`, `billing_period`/`billing_cycle`, `supplier_reference`/`vendor_reference`?, `inactive`/`status`, `distributor`. Which field is a stable external key is unknown; `supplier_reference` is the best candidate.
4. Whether a subscription has `contract_id` directly or is linked only via the recurring invoice line (third-party docs suggest the latter: manual attach).
5. `ClientContract` shape (`/api/ClientContract`, `client_id`, `ref`, `ri_*` lines) and `/api/RecurringInvoice` write semantics.
6. Whether Halo recommends assets vs subscriptions for ISP services: no authoritative statement found. Inference: subscriptions are for billable recurring quantities, assets for the thing you support; Domains stay assets (current behaviour), internet service billing fits subscriptions (the user's request).
7. Whether billing quantity can be auto-updated from subscription count (Halo guide "Managing a Recurring Invoice's Lines Quantity, Price and Cost for Subscriptions" exists, content not read).

## 3. Reusable DomainDash code (verified by reading)

- `HaloPsaClient`: OAuth client-credentials token (cached, 401 re-auth once), `request()` (only GET retried, POST sent once), `IntegrationHttp::send` with provider name, `paginate()/paginateAll()`, `extractList()`, Cache::remember patterns for reference data (`listAssetTypes` 600s, ticket types `integrations.halo.reference_data_ttl`). Add `listItems($search,$includeInactive)`, `getItem($id)`, `listSubscriptions($clientId)`, `createSubscription()`, `updateSubscription()`.
- Rate limiter `halo-api` (config `integrations.halo.rate_limit_per_minute`, AppServiceProvider:63); jobs use `RateLimited('halo-api')` or `syncMiddleware('halo-api')`.
- Job pattern: `PushDomainsToHaloJob` (chunk of ids, per-item isolation, error on row `halo_sync_error`/`halo_synced_at`, `SyncRun` advance/noteError/recordRetry, rounds capped at 4, `runInline` for admin "Run now"). `SyncVocusServicesJob` uses `TracksSyncRun` and config `integrations.sync.queue`. Copy this shape for `PushVocusSubscriptionsToHaloJob`.
- Client mapping: `clients.halopsa_reference` (Halo client id), `HaloClient` model.
- Picker endpoints pattern: `IntegrationMappingController::haloAssetTypes` (admin-only JSON, routes web.php:116-120) and DDList (`resources/js/dd-list.js`, sortable/filterable list) for the live filter.
- Models: `VocusPlan` (fillable has cost_price, sell_price, technology, active), `InternetService` (client_id, vocus_plan_id, cost_price, sell_price snapshot, service_status).

## 4. Implementation plan

**DB**
- `vocus_plans`: `halo_item_id` (unsigned bigint null, indexed), `halo_item_name` (string 255 null, cached label for display without API call).
- `internet_services`: `halo_subscription_id` (bigint null), `halo_synced_at`, `halo_sync_error` (string 1000), `halo_sync_hash` (char 64: hash of name/item/price/cost/qty/start/status/contract).
- Skip a local `halo_items` table in v1: items are searched live with a short cache. (Optional later: cache table if tenants have >5k items.)
- Settings keys: `halo.subscription_contract_strategy` (`client_active_contract` | `none`), `halo.subscription_contract_type_id` (optional filter), `halo.subscription_sync_enabled`.

**Item search proxy** `GET /admin/settings/halo/items?q=&include_inactive=0&page=` (admin only, throttle 30/min). Calls `GET /api/Item?search=q&pageinate=true&page_size=25&page_no=n&includeinactive=false`; returns `{items:[{id,name,description,price,cost,group,inactive}], total}`; cache per (q,page) for 60s. Validate q length <=100.

**Picker modal UX** (Settings → Vocus → Plans row): "Halo item" cell shows linked name or "Link". Modal: search box (debounce 250ms, abort previous fetch, min 0 chars loads first page), result list (name, group, price, id), keyboard up/down/enter, "Unlink" button, "Load more". Same item may be selected by many plans (no uniqueness). Saving PATCHes the plan with `halo_item_id` + `halo_item_name`; show warning if item inactive. Reuse DDList for rendering if it supports server-side search, else small Alpine/vanilla component.

**Sync job** `PushVocusSubscriptionsToHaloJob(serviceIds, runId)` triggered on: service ordered/activated (status becomes active), plan changed, client linked/changed, price change, cancellation. Debounce via hash comparison (skip if hash unchanged). Steps per service:
1. Preconditions: client has `halopsa_reference`, plan has `halo_item_id`; else record a clear error ("Plan X has no Halo item").
2. Find existing: by stored `halo_subscription_id`; otherwise `GET /api/SoftwareLicence?client_id=..&includeinactive=true` and match `supplier_reference == "domaindash:vocus:{vocus_service_id}"` (fallback match on name); this is the idempotency key (field name to confirm).
3. Payload: name = "{plan name} - {service identifier/address}", `item_id`, `client_id`, `count` 1, `price` = sell_price, `cost` = cost_price, start date = activation date, billing period monthly, contract id if resolved, reference key.
4. Create (POST once; never retry POST, so store id immediately) or update (POST with id). On cancellation set end date / inactive; do not delete.
5. Save `halo_subscription_id`, `halo_synced_at`, hash, clear error; failures set `halo_sync_error`, `SyncRun->advance(false)`; surface in existing DDSync run panel (provider `halo`, scope `subscriptions`) plus a badge on the internet service row with "Retry sync".
- Contract resolution: strategy `client_active_contract` -> `GET /api/ClientContract?client_id=..` choose active one (optionally of configured type); if none or ambiguous, create subscription without contract and flag "needs contract" warning; per-client override column `clients.halo_contract_id` (later). Whether the recurring invoice line must be created too is open (section 2 items 4,5): confirm manually in the tenant before building.

**Rollout order**: (1) read-only discovery spike: dump swagger from the real tenant and one Item and one existing SoftwareLicence; (2) migrations + item search + modal; (3) client methods; (4) job with dry-run mode; (5) triggers and retry UI; (6) tests with `Http::fake` for create/update/idempotent match/cancel/missing-item.

## 5. Assets vs subscriptions
No authoritative Halo recommendation found (unverified). Keep domains as assets; use subscriptions for billable ISP services as requested.
