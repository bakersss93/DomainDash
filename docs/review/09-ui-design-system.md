# 09 - UI consistency and design system

Task 9: "improve the consistency and overall look/feel of the UI design as this has variation throughout and could be improved to provide a cleaner modern platform."

Scope: Blade + Tailwind + Alpine/Livewire portal. No routes, form field names, element IDs, Alpine `x-data`, Livewire `wire:*` attributes, CSRF tokens or controller behaviour were changed.

## 1. Audit findings

Method: read `tailwind.config.js`, `resources/css/app.css`, `resources/js/app.js`, `layouts/*`, `components/*` and every view under `resources/views` (about 21k lines, 1,701 inline `style=""` attributes, 24 page-local `<style>` blocks), then rendered 22 pages in light, dark and 390px widths (before/after screenshots listed in the task report).

### 1.1 Structural problems

| # | Finding | Evidence |
|---|---|---|
| 1 | **Two competing token systems.** `app.css` had `--dd-*` tokens, while `layouts/app.blade.php` declared a second, branding-driven set (`--bg`, `--text`, `--accent`, `--border-subtle`, `--surface-muted` ...) in a 800-line inline `<style>`. The inline block loaded after `app.css`, so it silently overrode it (buttons, tables, inputs, modals). | `layouts/app.blade.php` lines 11-816 (before) |
| 2 | **Tailwind not wired to tokens.** `tailwind.config.js` only set the font. `bg-surface`, `text-muted`, `border-subtle`, `bg-primary` did not exist; Jetstream components used `gray-*`/`indigo-*` with `dark:` pairs while pages used `var(--...)`. | `tailwind.config.js` |
| 3 | **Pages redefined the design tokens locally.** `clients/index`, `services/show`, `dns/index` each re-declared `--dd-card-bg`, `--dd-card-border`, `--dd-pill-*` with hard-coded hex for light and dark. `services/show` even overrode the global `--dd-accent` with `#4ade80` (bright green vs the cyan used elsewhere). | style blocks of those pages |
| 4 | **Hard-coded dark theme.** `dd-account-*` (users create/edit, 320 lines duplicated in two files), the clients search input and the domains/purchase inputs used fixed dark hex (`#0f172a`, `#1e293b`, `#f8fafc`...) with `!important`, so they looked wrong in light mode; others had `html.dark { ... }` re-declarations. | 12+15+4+... `html.dark` rules |
| 5 | **No responsive shell.** Fixed 260px sidebar, no off-canvas behaviour: at 390px the content column was about 130px wide (see `dashboard-light-390`). | before screenshots |
| 6 | **Logo broken.** `Storage::url('')` rendered a broken image when no logo was uploaded; the Jargon logo only existed at the repo root. | sidebar "Logo" alt text |
| 7 | **Flash messages.** `session('status')` was always rendered green (even for errors such as "Unable to refresh DNS ..."). `session('success')` / `session('error')` (set by `ClientsController`, `ServicesController`) were never displayed anywhere. Eight pages re-rendered `status` a second time on top of the layout banner. | `DnsController:59`, `ClientsController:104,160` |
| 8 | **Accessibility gaps.** No global focus style (several inputs set `outline:none` with no replacement); sidebar toggles were `<div onclick>` (not focusable); form labels without `for` (audit filters, tickets); icon-only controls were emoji with only `title`; placeholder/muted greys `#9ca3af` on white (2.5:1). Input borders about 1.5:1. | audit |

### 1.2 Inconsistencies catalogued

* **Colours:** 400+ hex literals in views (top: `#566177` x71 as var fallbacks, `#6b7280` x69, `#9ca3af` x58, `#94a3b8` x41, `#ef4444` x36, `#b91c1c` x30, `#dc2626` x24). Three different "danger reds", four greys for muted text, three greens for success.
* **Buttons:** `btn-accent` (gradient, three different definitions), `btn-primary` (indigo gradient, from Breeze), `btn-secondary`, `dd-pill-btn` (12px radius, `!important`), `dd-users-create-btn` (999px pill), `dd-account-secondary`, `ak-btn-secondary`, plus about 90 inline-styled `<button style="padding:9px 12px;border-radius:10px;...">`. Radii in use: 4, 6, 8, 10, 12, 14, 999px.
* **Cards/panels:** `dd-card` (gradient + 45px shadow), `dd-clients-card` (18px radius), `dd-user-card`, `dd-account-card` (hard-coded dark), `ak-card`, `dd-stat-card`, `settings-section`, and inline `style="background:var(--surface-elevated);border:1px solid...;border-radius:12px"` blocks. Radii 12/14/16/18/20px.
* **Tables:** five systems (`main table` global, `dd-table-clean`, `dd-services-table`, `dd-clients-table`, `dd-dns-table`, `ak-table`) plus 20+ inline-styled tables. Different header colours, padding (6-12px), zebra vs none, with/without hover.
* **Form inputs:** `dd-input`, `dd-field`, `dd-pill-input`, `dd-account-input`, `dd-user-field input`, `ak-input`, `dd-ticket-field input`, `fancy-select`, `role-filter-select` and inline-styled controls: radii 8/10/12/999px, three focus treatments.
* **Badges / status:** `dd-badge`, `dd-status-badge`, `ak-badge`, `dd-status-*` coloured text, plain text status (`active`, `expired` as raw words in domains and dashboard), emoji ticks. Statuses were mostly un-styled text.
* **Page headers:** `h1` at 18px, 1.35rem, 1.45rem, 1.6rem, 24px, 28px, 30px, 2.2rem depending on the page; subtitles ad hoc.
* **Modals:** `dd-modal` (3 page-local copies), `ak-overlay`, `mfa-modal-backdrop`, `account-modal-backdrop`, `dd-account-modal-backdrop`, native `<dialog class="notif-modal">`, `dd-password-*`, Jetstream x-modal, and inline `position:fixed` overlays (domains, SSL, tickets, clients) with 5 different backdrop alphas.
* **Empty states:** a one-line grey `<td>` in each table; none shared.
* **Dark mode:** applied server-side by `<html class="dark">` from `users.dark_mode` (toggled by `POST /me/toggle-dark`). Layout re-declared variables under `html.dark`; many pages added their own `html.dark` fixes; the guest layout (login, 2FA challenge, reset) ignored dark mode entirely.
* **Dead code:** `navigation-menu.blade.php` (stock Jetstream top nav) and `welcome.blade.php` are not referenced by any route/layout.

### 1.3 Brand

`Jargon-Charcoal-v2-rectangle.png` (962 x 381): charcoal `#2b2b30`-ish background, brand green `#86b57a`, white wordmark. The previous UI used cyan (`#06b6d4`) as the accent, unrelated to the brand. The redesign takes **charcoal for the shell and green for the primary action colour**, and copies the logo to `public/images/jargon-logo.png` (the default when no logo is uploaded in Settings).

## 2. The design system

Single source of truth: CSS custom properties in `resources/css/app.css` (`:root` for light, `html.dark` for dark), mapped into `tailwind.config.js` (`theme.extend`). Legacy variable names (`--bg`, `--text`, `--text-muted`, `--border-subtle`, `--surface-muted`, `--surface-elevated`, `--accent`, `--primary`, `--danger-text`, `--success-*`, `--warning-*`) are kept as **aliases** of the new tokens, so the ~1,000 existing `var(--...)` usages keep working and now follow the theme.

### 2.1 Colour tokens

| Token (Tailwind class) | Light | Dark | Use |
|---|---|---|---|
| `--dd-canvas` (`bg-canvas`) | `#f5f6f8` | `#101114` | page background |
| `--dd-surface` (`bg-surface`) | `#ffffff` | `#17181c` | cards, inputs, popovers |
| `--dd-surface-soft` (`bg-surface-soft`) | `#f9fafb` | `#1c1d22` | fills inside a card, table headers, footers |
| `--dd-surface-muted` (`bg-surface-muted`) | `#eff1f4` | `#23252b` | hover, chips, secondary-button hover |
| `--dd-border` (`border-subtle`) | `#e2e5ea` | `#2b2e35` | dividers, card borders |
| `--dd-border-strong` (`border-strong`) | `#868c97` | `#6c7280` | control borders (>= 3:1) |
| `--dd-text` (`text-ink`) | `#1b1d22` | `#eceef1` | body text |
| `--dd-text-soft` (`text-muted`) | `#565c68` | `#a3a9b4` | secondary text |
| `--dd-text-faint` (`text-ink-faint`) | `#6f7581` | `#8d939e` | placeholders / disabled only |
| `--dd-accent` (`bg-primary`) | `#2f7d4b` | `#86b57a` | primary buttons, avatar |
| `--dd-accent-strong` (`bg-primary-hover`) | `#266a3e` | `#9ac78e` | hover |
| `--dd-accent-soft` (`bg-primary-soft`) | `#e8f3ec` | 14% green | tinted backgrounds |
| `--dd-accent-contrast` (`text-primary-fg`) | `#ffffff` | `#0f1a0f` | text on primary |
| `--dd-accent-text` (`text-primary-text`) | `#276a3f` | `#9ccb90` | links / accent text |
| `--dd-success` / `-danger` / `-warning` / `-info` | `#1a7f4b` `#c62828` `#b45309` `#2563eb` | `#3fb97c` `#ef6b6b` `#e0a030` `#6ea3f5` | icons, solid fills |
| `--dd-status-{success,warning,danger,info,muted}-{bg,fg}` (`bg-success-soft text-success-fg` ...) | e.g. `#e3f4ea / #14532d` | e.g. 16% green / `#7fe0ae` | badges, alerts |
| `--dd-sidebar`, `-soft`, `-text`, `-accent` | `#26272c`, `#303137`, `#c9ccd3`, `#86b57a` | `#15161a`, `#1d1e23` | sidebar (matches logo) |

Legacy Jetstream utilities are re-skinned in `tailwind.config.js`: `indigo-*` becomes a green scale centred on `#2f7d4b`, `gray-*` becomes the charcoal-tinted neutral scale, so un-migrated Jetstream views (profile, teams) match.

**Contrast (computed, WCAG relative luminance):**

| Pair | Light | Dark |
|---|---|---|
| text on surface | 16.9:1 | 15.3:1 |
| text-soft on surface / canvas | 6.7 / 6.2 | 7.5 / 8.0 |
| text-faint (placeholder) on surface | 4.6 | 5.7 |
| primary button text on accent | 5.1 | 7.6 |
| accent link text on surface | 6.5 | 9.6 |
| danger button text | 5.6 | 6.4 |
| badge text on tint (success / warning / danger / info / neutral) | 8.0 / 8.4 / 7.6 / 8.3 / 7.6 | 8.5 / 8.3 / 7.0 / 7.7 / 8.1 |
| sidebar text on sidebar | 9.3 | 11.2 |
| control border on surface (non-text, needs 3:1) | 3.4 | 3.7 |

### 2.2 Radius, elevation, typography, spacing

* **Radius:** `sm 6px` (small buttons, chips), `base 8px` (inputs, buttons, menus), `lg 12px` (cards), `xl 16px` (modals), `pill` (badges, avatar). Tailwind `rounded-sm/md/lg/xl` map to these.
* **Shadow:** `xs` (cards, buttons), `sm`, `md`, `lg` (menus/popovers), `overlay` (modals). Light uses cool-grey alpha, dark uses black alpha. The old 18px/45px shadow on every card is gone.
* **Typography:** Figtree (loaded from `fonts.bunny.net`, already allowed by the CSP) with system fallbacks. Scale: `xs .75rem` (labels, badges), `sm .875rem` (tables, controls), `base .9375rem` (body), `lg 1.125rem` (card titles), `xl 1.375rem`, `2xl 1.625rem` (page title, weight 700, -0.02em). Table headers are 12px uppercase tracking `0.04em`.
* **Spacing:** 4px base (`--dd-space-1..8`); page gutter 16px (mobile) / 24px (>=640) / 32px (>=1024); card padding 20px; form grid gap 16px; max content width 80rem (`ui-page`), 56rem for narrow forms.
* **Focus:** `:focus-visible` outline 2px `--dd-focus` (offset 2px) everywhere; form controls use a 3px translucent ring plus accent border; sidebar uses a light-green outline.

### 2.3 Components

Blade anonymous components in `resources/views/components/ui/` (CSS classes `ui-*` in `app.css`):

| Component | Props / slots | Notes |
|---|---|---|
| `<x-ui.page-header>` | `title`, `subtitle`, slot `actions` | the one `h1` per page; wraps on mobile |
| `<x-ui.card>` | `title`, `description`, `flush`; slots `actions`, `footer` | `flush` removes body padding for tables |
| `<x-ui.button>` | `variant` primary/secondary/danger/ghost, `size` sm/md/lg, `href` (renders `<a>`), `type`, `icon`, `block` | links navigate, buttons act (semantics) |
| `<x-ui.icon-button>` | `icon`, `label` (aria-label + tooltip), `variant`, `href`, `type` | replaces emoji action buttons |
| `<x-ui.icon>` | `name`, `size` | 28 inline SVG icons, `aria-hidden` |
| `<x-ui.badge>` | `variant` success/warning/danger/info/neutral/primary, or `status="expired"`; `dot` | `status` is mapped by `App\Support\UiStatus::variant()` |
| `<x-ui.table>` | `compact` | responsive scroll wrapper, uppercase header, row hover |
| `<x-ui.field>` | `label`, `name`, `type`, `as` input/select/textarea, `value`, `help`, `error`, `required` | label tied by `for`/`id`, `aria-invalid`, `aria-describedby`, old()/error auto-resolved; never echoes password values |
| `<x-ui.alert>` | `variant`, `title`, `dismissible` | `role="alert"` for danger/warning, `role="status"` otherwise |
| `<x-ui.empty-state>` | `title`, `text`, `icon`; slot actions | |
| `<x-ui.stat>` | `label`, `value`, `hint`, `variant`, `href` | KPI tile; `.ui-stat-grid` lays them out |
| `<x-ui.modal>` | `id`, `title`, `size`, `open`; slot `footer` | same markup as the existing `.dd-modal` pattern (container + `.dd-modal-backdrop` + `.dd-modal-dialog`); `[data-modal-open]`, `[data-modal-close]`, Esc and scrim-click handled in `resources/js/app.js` |

`App\Support\UiStatus` (`variant()`, `label()`) maps free-text statuses: active/enabled/issued/completed -> success; pending/suspended/expiring/in progress -> warning; expired/terminated/failed/cancelled -> danger; new/open/draft -> info; everything else -> neutral (unit tested).

Existing Jetstream components were upgraded in place (not duplicated): `button`, `secondary-button`, `danger-button`, `input`, `label`, `checkbox`, `input-error`, `validation-errors`, `action-message`, `modal`, `dialog-modal`, `confirmation-modal`, `banner`, `dropdown`, `dropdown-link`, `nav-link`, `responsive-nav-link`, `section-title`, `form-section`, `action-section`, `authentication-card(-logo)`. `ui-modal`, `dd-modal*`, `dd-alert*`, `dd-badge*`, `dd-status-*` classes are kept as aliases of the new styles.

### 2.4 Backwards-compatible class names

`btn-accent`, `btn-primary`, `btn-secondary`, `btn-danger`, `btn-success`, `btn-warning`, `dd-card`, `dd-page`, `dd-page-title`, `dd-input`, `dd-field`, `dd-checkbox`, `dd-table-clean`, `dd-services-table`, `dd-clients-table`, `dd-dns-table`, `dd-alert*`, `dd-badge*`, `dd-status-*`, `dd-modal*`, `dd-combobox*`, `client-picker*`, `fancy-select*`, `role-filter*`, `dd-account-*`, `dd-user-*`, `dd-settings-page`, `mfa-*`, `account-modal*`, `notif-*` all still exist, redefined on tokens in `app.css` (flat fills instead of gradients, one radius scale, one shadow scale). Page-local copies of the same rules (modal, combobox, dark overrides, token re-declarations) were deleted in favour of the shared ones.

### 2.5 Application shell

* Sidebar (charcoal, logo + "DomainDash", active item with green indicator, `aria-current="page"`), same IA and all links; section toggles are now `<button aria-expanded>`; collapsed submenus are `visibility:hidden` so they are not tab stops.
* Sticky topbar: hamburger (< 1024px), dark-mode toggle (same `POST /me/toggle-dark`), notifications bell (SVG, badge only when unread > 0), account menu (closes on outside click / Esc). Skip-to-content link. Off-canvas sidebar with scrim below 1024px.
* Dark mode: unchanged mechanism (server-rendered `class="dark"` from `users.dark_mode`). Guest pages (login, 2FA challenge, forgot/reset) now follow `prefers-color-scheme`.
* Branding (Settings): an admin-chosen accent/primary/bg/text colour still overrides the tokens (validated as hex; emitted only when it differs from the previous built-in defaults, so installs that never customised pick up the new brand). Settings form defaults updated to the new palette.
* Flash: `status` is red when the message reads as a failure, green otherwise; `success` and `error` flashes are now displayed; duplicate per-page `status` banners removed.

## 3. Coverage

**Fully restyled with the component set (header, cards, tables, badges, buttons, fields, alerts, empty states):**
`layouts/app`, `layouts/guest`, `auth/login`, `dashboard` (customer), `admin/domains/index`, `admin/users/index`, `admin/clients/index`, `admin/ssls/index`, `tickets/{requests,index,show,create}`, `apikeys/index`, `admin/audit/index` (header, labelled filters), `admin/settings/index` (header, icon tiles, flat accent buttons, alerts, branding defaults), notifications dialog, MFA / account / password modals.

**Token-level only (shared CSS + variable aliases + hex-to-token pass + h1 normalisation; markup structure untouched):**
`admin/dashboard/index` (System Status), `admin/users/{create,edit,password}` (320-line hard-coded dark style block removed; now themed), `admin/clients/{form,create}`, `admin/domains/{show,pricing,purchase,transfer}`, `admin/services/{index,show,hosting,hosting-purchase,ssl-purchase}`, `admin/services/internet/*` (info/error banners converted to `<x-ui.alert>` where simple), `admin/ssls/show`, `admin/notifications/templates`, `admin/permissions/index`, `dns/index` (token aliases + table styles unified), `profile/*`, `teams/*`, `auth/{register,forgot,reset,confirm,verify,two-factor}`, `apikeys/api-token-manager`, `policy`, `terms`.

**Not touched:** `emails/*` (out of scope), `welcome.blade.php` (stock Laravel, unreferenced), `navigation-menu.blade.php` (stock Jetstream top nav, unreferenced).

Mechanical passes applied to every non-email view: `h1` inline size overrides stripped (one title style), inline `color:#hex` greys/reds/greens/ambers mapped to tokens, hard-coded tinted banner backgrounds mapped to status tokens, inline `border/radius/padding/background/color` stripped from text inputs/selects/textareas so they inherit the shared control style, ad-hoc banner `<div>`s converted to `<x-ui.alert>`.

## 4. Small defects fixed on the way

* `tickets/requests.blade.php` and `tickets/index.blade.php`: "Load next 25" injected PSA ticket fields into `insertAdjacentHTML` unescaped; now HTML-escaped (`tkEsc`) and the ticket id is `encodeURIComponent`-ed in the row link.
* `admin/clients/form.blade.php` and `admin/clients/index.blade.php`: IT Glue / HaloPSA organisation names and references rendered into `tr.innerHTML` unescaped; now escaped through `ddEscHtml`.
* Ticket rows are keyboard operable (`tabindex`, Enter).
* `ui.field` tolerates rendering without an `$errors` bag.

Known remaining `innerHTML` concatenations that interpolate server error strings (not user-controlled markup, but worth a follow-up in the security backlog): `admin/clients/form.blade.php` sync status handlers (`statusDiv.innerHTML = ... data.error ...`).

## 5. Deferred / follow-ups

* Deeper conversion of the long-tail admin screens (`services/show`, `domains/show`, `internet/*`, `settings` sub-forms, `clients/form`) from page-local inline styles to `<x-ui.*>`; they are consistent at token level but still carry inline spacing.
* Retire legacy aliases (`dd-pill-*`, `ak-*`, `dd-account-*`, `dd-user-*`) once those pages are migrated.
* Unreferenced `navigation-menu.blade.php` / `welcome.blade.php` could be deleted.
* Local dev: CSP report-only header lists an invalid `http://[::1]:5173` source (console noise only, pre-existing).
* A visual-regression run (Playwright screenshots) is not part of CI; screenshots were taken manually with the seeded SQLite database.
