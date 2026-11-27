# 16 - HaloPSA subscriptions / recurring invoices: swagger facts

Source: the user's own tenant swagger (OpenAPI 3.0.1, 961 paths), read with a script that extracted only the paths and schemas below. Replaces the "unverified" list in `15-halo-items-subscriptions-research.md`. **VERIFIED** = read in the swagger. **INFERRED** = a reasoned reading that must be confirmed with one live call (see section 6).

## 1. Endpoints

| Use | Endpoint | Query params / body | Status |
|---|---|---|---|
| Item search | `GET /Item` | `search`, `pageinate`, `page_size`, `page_no`, `order`, `orderdesc`, `includeinactive`, `includeactive`, `activeinactive`, `recurringonly`, `excluderecurring`, `count`, `assetgroup_id`, `supplier_id`, `advanced_search` | VERIFIED |
| One item | `GET /Item/{id}` | `includedetails` | VERIFIED |
| Subscriptions list | `GET /SoftwareLicence` | `client_id`, `site_id`, `search`, `includeinactive`, `licence_type`, `tenant_id`, `toplevelid`, `pageinate`, `page_size`, `page_no`, `count`, `order*` | VERIFIED |
| One subscription | `GET /SoftwareLicence/{id}` | `includedetails` | VERIFIED |
| Create / update subscription | `POST /SoftwareLicence` | body is an **array** of `Licence_List`; an object with `id` updates | VERIFIED (array); update-by-id INFERRED (same convention as `/asset`, already used in this app) |
| Client contracts | `GET /ClientContract` | `client_id`, `includeinactive`, `contract_type`, `contract_sub_type`, `excluderenewed`, `pending_recurring_invoice`, `search`, paging | VERIFIED. Response schema `ContractHeader_View`: `{page_no,page_size,record_count,contracts[]}` |
| Recurring invoices | `GET /RecurringInvoice` | `client_id`, `contract_id`, `includelines`, `include_linked_item_details`, `recurringinvoice_id`, `search`, `start_date`, `end_date`, paging | VERIFIED. Response `InvoiceHeader_View`: `{page_no,page_size,record_count,invoices[]}` of `InvoiceHeader` |
| One recurring invoice | `GET /RecurringInvoice/{id}` | `includedetails` | VERIFIED (`InvoiceHeader`, has `lines[]`) |
| Add lines | `POST /RecurringInvoice/Lines` | array of `InvoiceDetail` | VERIFIED path/body; semantics INFERRED (add) |
| Update lines | `POST /RecurringInvoice/updatelines` | array of `InvoiceDetail` | VERIFIED path/body; semantics INFERRED (update by line id) |
| Create/update recurring invoice | `POST /RecurringInvoice` | array of `InvoiceHeader` | VERIFIED. **Not used**: DomainDash never creates invoices (default off, not implemented) |
| Line listing | `GET /RecurringInvoice/lines` | no params documented | VERIFIED, unused |

Tags on the path items are `Item`, `Licence`, `RecurringInvoiceHeader`, `ClientContract`. The swagger declares **no response schema** for `GET /Item` and `GET /SoftwareLicence` (just "OK"), so list envelopes are INFERRED: `{record_count, items[]}` and `{record_count, licences[]}`; `HaloPsaClient::extractList` also accepts `data` / `Results` / a bare list.

## 2. Item (`Item` schema, 239 fields) - fields used

`id` int, `name` string, `description`, `internalreference`, `externalreference`, `supplier_part_code`, `qbosku` (first non-empty becomes the displayed "code"), `baseprice` number (sell), `costprice` number, `recurringprice` number, `recurringcost` number, `isrecurringitem` bool (the "recurring flag"), `assetgroup_name`, `update_recurring_invoice_price` bool, `update_recurring_invoice_cost` bool. There is **no `inactive` field** on `Item`; inactive filtering is the `includeinactive` list parameter only.

## 3. Subscription = `SoftwareLicence` (`Licence_List` schema, 89 fields) - fields used

`id`, `client_id`, `site_id`, `name`, `name_extra`, `count` (quantity, number), `price` number, `purchase_price` number, `monthly_price`, `monthly_cost`, `start_date` date-time, `end_date` date-time, `is_active` bool, `status` string, `billing_cycle` string, `term_duration` string, `autorenew` bool, `notes` string, `item_id` int, `linked_item` string, `product_sku`, `vendor_product_sku`, `third_party_productid`, `_create_recurring_invoice_line` bool, `allow_licence_update` bool, `supplier_id`, `deleted` bool.

- There is **no reference / external-id field** apart from free text. DomainDash writes the marker `[[DOMAINDASH-SVC:<vocus_service_id>]]` as the first line of `notes` and finds an existing subscription with `GET /SoftwareLicence?client_id=..&includeinactive=true&search=<service id>` then a client-side `notes` match (INFERRED that `search` covers notes; the client-side filter keeps it correct either way, at the cost of paging the client's subscriptions). The returned id is stored in `internet_services.halo_subscription_id`, so the search only runs when that id is missing.
- `price` is read as the sell price and `purchase_price` as the cost (INFERRED from the names; `monthly_*` look calculated and are not written). `billing_cycle`, `status`, `term_duration` are free strings whose accepted values are not in the swagger and are not written.
- `_create_recurring_invoice_line` exists, but which invoice it would pick is undocumented, so DomainDash creates the line itself on a chosen invoice.

## 4. Recurring invoice line = `InvoiceDetail` (260 fields) - fields used

`id`, `recurring_invoice_id`, `recurring_invoice_line_id`, `_itemid` (item), `item_shortdescription`, `qty_order` number, `unit_price`, `unit_cost`, `startdate`, `enddate`, `isinactive` bool, `isActive` (read only), `recurring_invoice_quantity_type` int (enum not documented), `quantity_subscriptions[]` of `InvoiceDetailQuantity` = `{licence_id, licence_name, use_linked_subscription_price, use_linked_subscription_cost, invoice_line_id, kind, qty_free, minimum_qty, pro_rata, ...}`. This is how a subscription is **attached to a recurring invoice line**: a line carries `quantity_subscriptions[].licence_id`.

`InvoiceHeader` (recurring invoice): `id`, `client_id`, `name`, `contract_id`, `contract_ref`, `reference`, `is_recurring_invoice`, `startdate`, `enddate`, `status`, `period`, `period_type`, `lines[]`.

`ContractHeader_List` (contract list rows): `id`, `client_id`, `ref`, `contracttype`, `contracttype_name`, `active`, `status`, `contract_status`, `start_date`, `end_date`. The recurring invoice to contract link is `InvoiceHeader.contract_id`.

## 5. What DomainDash does with it

1. Search items, store `vocus_plans.halo_item_id` / `halo_item_name`.
2. Per service: find/create/update the subscription (POST array, with `item_id`, `count` 1, `price`, `purchase_price`, `start_date`, `notes` marker, `is_active`, `end_date`).
3. Resolve the recurring invoice: the client's chosen one, else (strategy "contract") `GET /ClientContract?client_id&includeinactive=false[&contract_type]`, filter `ref`, then `GET /RecurringInvoice?client_id&contract_id`; exactly one must remain.
4. `GET /RecurringInvoice/{id}?includedetails=true`, find the line whose `quantity_subscriptions[].licence_id` equals the subscription id (or the stored line id); `updatelines` it, else `Lines` to add it.
5. Cancellation: subscription `is_active=false` + `end_date`; line `isinactive=true` + `enddate`. Nothing is deleted.

## 6. Inferred points to confirm with one live test (use "Sync to Halo" on a test client)

1. `POST /SoftwareLicence` with `id` updates (and echoes the saved object with `id`; if not, the code re-finds it by marker).
2. `price` = sell and `purchase_price` = cost, and Halo accepts `end_date` null on create.
3. `Lines` adds, `updatelines` updates, and both echo the line with `id`.
4. Whether a line with a fixed `qty_order` of 1 plus `quantity_subscriptions` is what the Subscriptions tab shows as "attached". If your tenant wants the quantity driven by the subscription, find the `recurring_invoice_quantity_type` id (an existing line with the right setting, read via `GET /RecurringInvoice/{id}?includedetails=true`) and set `HALO_RECURRING_QUANTITY_TYPE`.
5. `GET /SoftwareLicence?search=` matches `notes` (only affects the number of pages read).
6. Halo item `update_recurring_invoice_price/cost` flags may overwrite the line price from the item when the item changes; DomainDash sends the plan's sell/cost on every update.

## 7. Agreements (ClientContract) and line text: facts used by the agreement rule (added)

Read from the tenant swagger (`/ClientContract`, `ContractHeader_List`, `InvoiceDetail`, `/Lookup`).

| Use | Field / endpoint | Status |
|---|---|---|
| Filter agreements | `GET /ClientContract` query `client_id`, `includeinactive`, `contract_type` (int, "only Contracts with the specified Contract Type"), `contract_sub_type` (int, "...Contract Sub-Type"), `labour_type` (0=Fixed, 1=Pre-Pay, not used) | VERIFIED |
| Agreement row | `ContractHeader_List`: `id`, `client_id`, `ref` (Agreement Reference), `refextra`, `refextra2`, `contracttype` (int), `contracttype_name`, `subtype` (int, **no name field**), `active`, `status` | VERIFIED |
| Contract type list | none in the swagger: distinct `contracttype` + `contracttype_name` from `GET /ClientContract` (paged, capped at 10 pages) | VERIFIED (no endpoint) |
| Subtype names | `GET /Lookup?lookupid=<n>` returns `Lookup` rows (`id`, `name`, `inactive`). The swagger does **not** say which `lookupid` is the agreement subtype list | VERIFIED endpoint, lookupid UNKNOWN: entered in Settings (`halo.billing.subtype_lookup_id`); without it subtypes are listed as "Subtype #id (e.g. <agreement reference>)" from agreements |
| Agreement to invoice | `InvoiceHeader.contract_id` (+ `contract_ref`), `GET /RecurringInvoice?client_id=` | VERIFIED |
| Line description | `InvoiceDetail.item_shortdescription` (string) | VERIFIED |
| Line long description | `InvoiceDetail.item_longdescription` (string) | VERIFIED |
| Item / SKU | `InvoiceDetail._itemid`, `item_code` (read from the item), `qty_order`, `unit_price`, `unit_cost` | VERIFIED |
| Client code | `Area.ref` on `GET /Client/{id}` (used for `{client_code}`) | INFERRED: the name matches "Client reference"; confirm with one live call |

Selection (HaloAgreementResolver): (1) invoice picked on the service (`internet_services.halo_override_invoice_id`); (2) strategy "contract": client's active agreements filtered by type + subtype + reference pattern (tokens filled from the service, empty token = wildcard); one left = use it; several = the one whose reference contains the service id / username / AVC / LOC, then plan name / id; still several = error listing them; (3) the invoice pinned on the client is the fallback when the rule finds nothing or is ambiguous (strategy "client" uses only the pin). The agreement's recurring invoice is the one with `contract_id` = agreement id; none or several = clear error.

Line text templates: DomainDash placeholders `{plan_name} {plan_id} {speed} {service_id} {vocus_service_id} {avc_id} {loc_id} {username} {address} {ip_address} {technology} {client_code} {client_name}` (`{plan}` also in the reference pattern); Halo variables (`$PERIODSTARTDATE`, `$PERIODENDDATE`, agreement/recurring billing/asset variables) are never touched. Defaults: description `{plan_name} - $PERIODSTARTDATE - $PERIODENDDATE`; long description `{plan_name} - Billing Period: $PERIODSTARTDATE - $PERIODENDDATE` / `Service ID: {service_id}` / `AVC: {avc_id}`.

Still to confirm live: the `lookupid` of the subtype list, that `Area.ref` is the client code, and that Halo expands `$VARIABLES` in `item_longdescription` sent through the API.
