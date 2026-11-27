# 18. Settings redesign (Halo-inspired IA + mapping tables)

## Problem
`/admin/settings` was one ~3,000 line page of collapsible sections with every list of matches (ticket types, statuses, clients, organisations, asset fields, traits, realms) built from bespoke inline forms, modals and JS.

## Information architecture
HaloPSA pattern: a Configuration home of categorised tiles, then one page per module with horizontal tabs, compact cards, modals for add/edit, tables for lists.

| Home category | Tile -> page | Tabs |
|---|---|---|
| General | Branding `/admin/settings/branding` | Branding |
| | Email (SMTP) `/email` | SMTP (+ test mail) |
| | Backups `/backups` | Backups (SFTP, retention, disk alert) |
| Security | Platform HTTPS `/admin/https` (own page, unchanged) | |
| | MFA and audit `/mfa` | Multi-factor, Audit log |
| Integrations | HaloPSA `/halopsa` | Connection, Clients, Assets, Tickets, Billing, Sync |
| | IT Glue `/itglue` | Connection, Organisations, Assets, Sync |
| | Synergy `/synergy`, IP2WHOIS `/ip2whois` | Connection |
| | Vocus `/vocus` | Connection, Ordering (realms, scopes), Plans, Sandbox |
| Billing | Halo billing -> `/halopsa#billing` | |
| Sync and queue | Sync scheduler `/sync` | Schedule |

Deep links: `#tab` or `?tab=`; `/admin/settings?section=halo` etc. redirect to the area. Registry: `App\Support\Settings\SettingsAreas`. Tab partials: `resources/views/admin/settings/tabs/{area}/{tab}.blade.php`. Deviation from the brief: HaloPSA gets a **Clients** tab (client mapping table) rather than burying it in Sync.

Saving: form tabs post to `SettingsController@update` with `_return` (own URL + tab; restricted to `/admin/settings...`). The controller now **merges every posted group recursively into the stored one** (keyed arrays merge, lists replace), so a tab posting one key never wipes the rest; secrets keep the `********` sentinel behaviour. Mapping tables save through their own endpoints.

## Mapping-table component
`<x-ui.mapping-table>` + `resources/js/dd-mapping-table.js` (vanilla, no deps).
- Columns, "1-10 of N" pager with prev/next, green "+ Add", pencil/bin on row hover or focus (always visible on touch), optional search, optional toolbar actions (e.g. Refresh from Halo), loading / empty / error+Retry states, delete confirm dialog.
- Add/Edit dialog: accessible combobox per side (role=combobox/listbox, type-to-filter, arrows/Home/End/Enter/Esc, clear x, optional "Refresh list", custom value, server-side search, focus trap). Also `select` and `text` fields.
- Modes: `remote` (GET list / POST / PUT {id} / DELETE {id}) and `local` (rows in page; `dd-mapping:change` event; `fixed` = edit only). Modal variant: `modal="id"` renders a button and the table inside `x-ui.modal`. Tables initialise when first visible (hidden tabs/dialogs cost nothing).
- Server strings are always written with `textContent`. Tokens only; light and dark follow `--dd-*`.

Endpoints (Administrator group, throttle 60/min):
- `GET /admin/settings/options/{source}?q&limit&refresh=1&include=ID&asset_type_id&type_id` -> `{options:[{id,label,meta?}],total,truncated,cached,error?}`. Sources: halo.ticket-types, statuses, priorities, teams, slas, sites, contract-types, asset-types, asset-fields, items, clients(mirror); itglue.organisations(mirror), flexible-types, flexible-fields; dd.clients, service-categories, status-labels, asset-points, itglue-points, ticket-classes. Remote lists cached 10 min per tenant; `refresh=1` bypasses; `include` keeps a saved value selectable.
- `GET|POST /admin/settings/mappings/{mapping}`, `PUT|DELETE .../{id}` for halo.ticket-types, halo.statuses, halo.clients, itglue.organisations, vocus.realms. Responses `{rows:[{id,cells,values}],total,page,per_page}`.

Halo contract types: the swagger has **no ContractType list endpoint**; `HaloPsaClient::listContractTypes()` derives distinct `contracttype`/`contracttype_name` from `GET /ClientContract` (paged, capped at 10 pages). Other lists use `/Priority?includedistinct`, `/SLA`, `/Team`, `/Site`, `/TicketType`, `/Status`, `/AssetType` (verified in the swagger).

## Mapping inventory
| Current list / array setting | Now |
|---|---|
| `halo.ticket_type_mappings` (list) | Tickets tab, inline mapping table (`halo.ticket-types`), same stored shape |
| `halo.status_mappings` (list) | Tickets tab button -> modal with mapping table (`halo.statuses`) |
| `clients.halopsa_reference` (Halo client map) | Halo Clients tab table (`halo.clients`); sync modal client panel removed |
| `clients.itglue_org_id` (org map) | IT Glue Organisations tab table (`itglue.organisations`) |
| `halo.asset_type_id/name` | searchable asset type picker (Assets tab) |
| `halo.asset_field_ids/names` | local fixed mapping table "DomainDash value -> Halo field" + "Suggest from Halo"; manual IDs in collapsed Advanced |
| `halo.asset_key_fields[1..3]` | three compact selects (names unchanged) |
| `halo.billing.contract_type_id` | picker populated from Halo contract types |
| `itglue.flexible_asset_type_id/name` | searchable type picker |
| `itglue.flexible_asset_traits` | local fixed mapping table, fields from IT Glue; manual keys in Advanced |
| `vocus.realms` | Ordering tab table (`vocus.realms`) |
| `vocus.scopes`, plans, plan mapping, tiers | kept as dialogs (Ordering / Plans tabs) |
| `sync_schedule`, `mfa`, `audit`, `smtp`, `backup`, `notifications`, branding, synergy, ip2whois | single-form tabs; keys unchanged (backup/audit/notifications had no UI before) |

## Not done / follow-ups
Browser check skipped (no vendor/, app cannot boot). The domain/config sync dialogs keep their legacy inline-styled markup (`partials/sync-*.blade.php`). Plans dialog is restyled only by the shared tokens.
