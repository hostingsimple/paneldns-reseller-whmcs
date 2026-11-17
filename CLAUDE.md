# PanelDNS Reseller WHMCS Module — Development Specification

> **Ecosystem context:** for how this repo fits with the SaaS platform and the platform
> WHMCS module — and the shared-file sync rules — see `ECOSYSTEM.md` in the main
> `paneldns` repo (https://github.com/Veeau/paneldns/blob/master/ECOSYSTEM.md).

## AI Assistant Rules

- **Check / scan / audit / question → report only.** When asked to check, scan, audit, review,
  or answer a question, produce a findings report and stop. Do NOT write code, edit files,
  commit, or push. Wait for explicit instruction before making any change.
- **Explicit instruction required before any write.**
- **README.md must be updated with every release.**

## Version history (summary)

| Version | Date | Notes |
|---|---|---|
| `1.0.0` | 2026-05-30 | Initial public release (split from paneldns-whmcs) |
| `1.1.0` | 2026-05-30 | Domain lifecycle automation, addon products, usage/health widgets |
| `1.2.0` | 2026-05-30 | Bulk sub-client sync, `searchSubClients()` API helper |
| `1.2.1` | 2026-05-30 | Security hardening — first OWASP audit |
| `1.3.0` | 2026-05-30 | AdminSingleSignOn, NS card, resend welcome, ListAccounts, ClientEdit hook, grace period |
| `1.3.1` | 2026-05-30 | SSO redirect fix, ServiceSingleSignOn, UsageUpdate, test suite expansion |
| `1.4.0` | 2026-05-30 | build.php ZIP fix, SMOKE-TEST.md, dependabot, CI improvements |
| `1.4.1` | 2026-06-03 | Security hardening — full OWASP re-audit (post-1.2.1 surface) |
| `1.5.0` | 2026-06-03 | Auto-unsuspend on payment, NS in service notes, zone quota pre-flight |
| `1.6.0` | 2026-06-03 | BIND zone export, client locale sync, `raw_body` in API responses |
| `1.7.0` | 2026-06-03 | DNSSEC card in the embedded DNS manager |
| `1.7.1` | 2026-06-03 | `shared/PanelDnsApi.php` synced from paneldns-whmcs |
| `1.7.2` | 2026-06-06 | OWASP hardening pass — SSRF pre-flight, error leakage, log sanitisation |
| `1.8.0` | 2026-06-07 | Consent stamping at provisioning (untagged) |
| `1.9.0` | 2026-06-07 | Consent fields on sub-client create (untagged) |
| `1.9.1` | 2026-06-16 | `PanelDnsReseller*` class prefix — coexists with the platform module (untagged) |
| `1.9.2` | 2026-08-27 | Past-due grace clock fix; `curl_close()` removed |
| `1.9.3` | 2026-11-08 | Rename to `paneldnsreseller` + migration; self-contained ZIP; `getLegalVersion` shipped; import `zone_file`; paging; duplicate email; grace cron; errors/429 |
| `1.9.4` | 2026-11-18 | Security: no account linking by email — Bulk Sync reports matches, duplicate-email Create never links, sub-client id from Dedicated IP only; grace cron skips a re-linked sub-client |
| `1.9.5` | 2026-11-18 | Security: zones only for the client's own domains (configoption12 opt-out with overlap check); auto-create on `DomainTransferCompleted`, not `AfterRegistrarTransfer`; generic "unavailable" for name conflicts; PAY-02: removed the `InvoicePaymentSuccess` hook that unsuspended on any payment |
| `1.9.6` | 2026-11-18 | Security (rescan Medium/Low): drift sync treats WHMCS as source of truth, never terminates on 404; only `PanelDNS +N Zones` addons, limit recomputed; client-area DNS needs an Active service and the `productsso` permission; Terminate deletes the zones first; delete-confirm attribute escaping; generic 0/5xx client errors; SSRF resolve-once-and-pin; deterministic service for domain hooks |

## Overview

A WHMCS provisioning module (server module) that connects a reseller's WHMCS installation
to their PanelDNS account. When a WHMCS client orders the DNS product, this module
provisions them as a sub-client in the reseller's PanelDNS org via the PanelDNS REST API
(`/api/v1`).

**Target audience:** PanelDNS resellers who use WHMCS to manage and bill their clients.

## Relationship to paneldns-whmcs (private repo)

This repo was extracted from `Veeau/paneldns-whmcs` (private) and made public so resellers
can audit and trust the code.

### Module name

The WHMCS module name is **`paneldnsreseller`** — folder, main file, function prefix
(`paneldnsreseller_*`) and `tblproducts.servertype` / `tblservers.type`. WHMCS calls
`<folder>_<Function>` and requires a single word of lowercase letters and numbers. Never
reintroduce a hyphen or underscore. `PanelDnsResellerMigration` moves pre-1.9.3 installs
(`paneldns-reseller`, `paneldns_reseller`); the operator module is `paneldnsplatform`.

Client-area pages are selected by `?customAction=` (not `modop=custom&a=`, which needs a
real module function per page).

WHMCS contract details that were wrong before 1.9.3 — keep them right:
- `ServiceSingleSignOn` / `AdminSingleSignOn` return `['success' => true, 'redirectTo' => $url]`
  (not `redirectUrl`), or `['success' => false, 'errorMsg' => …]`.
- `localAPI('SendEmail', …)` takes `customvars` as a plain **array** (WHMCS also accepts
  `base64(serialize())`; never `base64(json_encode())`).
- A ticked yes/no module option is stored as `'on'` — test with `PanelDnsResellerHooks::isYes()`.
- `logModuleCall()`'s 6th argument masks literal **values**; pass secret values, never key
  names. `PanelDnsResellerApi::scrub()` redacts secret keys at any depth.
- Servertype filters use `PanelDnsResellerHooks::SERVERTYPES` (the name + legacy spellings);
  the drift sync covers this module only — the platform module reconciles its own.

### Account linking — never by email (v1.9.4, NO-EMAIL-LINK-01/02/03)

WHMCS does not verify client emails, so anything a client typed is attacker-controlled.
A service's sub-client is the id PanelDNS issued for it, stored in `tblhosting.dedicatedip`
(admin-only) — `subClientId()` reads nothing else. Never link, adopt or re-activate an
existing sub-client because its email (or any client-fillable field, e.g. a product
custom field) matches. A duplicate-email 422 fails CreateAccount with a message; Bulk Sync
lists email matches under "Needs review" and links nothing. Linking an existing sub-client
is a per-service admin step: enter the id in Dedicated IP and run Create. Email lookups
(`findSubClientByEmail()`) may only feed messages and review lists.

### Zone ownership (v1.9.5, ZONE-OWN-01..05)

A reseller's customers share its nameservers, which answer from the most specific zone, so
a zone for someone else's name is a takeover of that name. Every path that creates a zone
must prove the name is the client's:

- Client area (`EmbeddedDnsManager::zoneCreateRefusal()`): a `tbldomains` row of the
  client (`$params['userid']`), exact lower-cased name, status `Active`. `Pending Transfer`
  is not ownership. `tblhosting.domain` (`$params['domain']`) is client-typed at order time
  and is never proof.
- `configoption12` "Allow Zones for Domains Not in This Account" (yesno, default off) lifts
  that, but `overlapRefusal()` still refuses a name equal to, above or inside any zone whose
  `sub_client_id` is not this service's (org-level zones included). It pages every listing
  and fails closed on an error or a truncated listing. New options go last: WHMCS stores
  them by position.
- Hooks: `AfterRegistrarRegistration` (successful register) and `DomainTransferCompleted`
  (sync cron). Never `AfterRegistrarTransfer`: it runs on submission, "regardless of success
  state". `onDomainEvent('create')` re-checks `tbldomains` itself.
- A 409 or a name-conflict 422 (`isNameConflict()`) shows only "This zone name is
  unavailable."; the detail goes to `logActivity()`. Never show PanelDNS's conflict wording
  to a client.

### Suspension and payment (v1.9.5, PAY-02)

No hook may unsuspend a service or write `tblhosting.domainstatus` on payment. WHMCS's
Enable Unsuspension already calls `UnsuspendAccount`, at payment time, only for services
it suspended for non-payment ("Overdue on Payment"), and `InvoicePaid` runs after it.
`InvoicePaymentSuccess` is not a WHMCS hook; the invoice hooks (`InvoicePaid`,
`InvoicePaidPreEmail`, `AddInvoicePayment`) pass `invoiceid`, not `userid`.
`tests/InvoicePaymentTest.php` fails if any of them is registered.

### WHMCS is the source of truth for status (v1.9.6, DRIFT-SOT-01, DRIFT-404-01)

`PanelDnsResellerDriftSync::decide()` only moves a service towards the more restrictive
state (Active → Suspended/Terminated, Suspended → Terminated) and re-suspends in PanelDNS a
sub-client that is active while WHMCS has the service Suspended. It never writes Active and
never touches a Pending service (logged for review). A 404 never terminates: it adds
`[paneldns-drift:not-found]` to the admin notes, and only when 404s are the exception on
that server; a mostly-404 server (3+) or a 401/403 is a token/org mistake and is left
alone for the run. Do not add any path that copies an upstream status into WHMCS that is
less restrictive than WHMCS's.

### Client-area access (v1.9.6, WHMCS-PERM-01, SVC-STATUS-01)

`clientArea()` gates `sso` and every `EMBEDDED_ACTIONS` page and form on two checks; a
refusal renders the overview with `paneldns_notice`:

- `$params['status'] === 'Active'` (a missing status refuses).
- `userMayManage()`: `\WHMCS\Authentication\CurrentUser` `user()` + `client()` (the client
  must be `$params['userid']`), then `Client::isOwnedBy($user)`, else
  `localAPI('GetUserPermissions', ['user_id', 'client_id'])` must list `productsso`
  ("Perform Single Sign-On", the permission WHMCS checks for ServiceSingleSignOn). Fails
  closed. `tests/Support/FakeWhmcsAuth.php` stands in for CurrentUser. A new client-area
  action must be in `EMBEDDED_ACTIONS` (or be `sso`) so the gate covers it.

### Addon zone packs (v1.9.6, ADDON-ZONES-01)

Only a predefined addon whose whole name matches `ZONE_PACK_PATTERN` (`PanelDNS +N Zones`)
grants zones; never add a fallback number. The zone limit is recomputed, never adjusted:
`limits()` = product/Configurable Option limit + `addonZones()` (active zone packs from
`tblhostingaddons`), 0 stays 0. The five addon state hooks re-apply it through
`localAPI('ModuleChangePackage')`, with `$addonStateOverride` carrying the state the addon
is moving to.

### Terminate deletes zones (v1.9.6, TERM-ZONES-01)

PanelDNS's `DELETE /sub-clients/{id}` deletes only the row (zones FK `nullOnDelete`, so
zones would stay live as org zones). `deleteSubClientZones()` runs before every
sub-client delete (TerminateAccount, grace cron), deletes only zones whose `sub_client_id`
matches, and on any failure keeps the sub-client (grace marker kept).

### Errors, templates, SSRF, domain hooks (v1.9.6)

- ERR-LEAK-01: client-visible PanelDNS errors go through
  `PanelDnsEmbeddedDnsManager::clientError()`: status 0 or ≥ 500 → `MSG_SERVER_ERROR`,
  detail to `logModuleCall()`; 4xx text is shown.
- TPL-ATTR-01: never output a Smarty variable inside an `on…="…"` attribute or
  `json_encode` outside `<script>`; pass values in `data-…="{$v|escape}"`.
  `tests/TemplateAttributeEscapingTest.php` enforces it for every template.
- SSRF-PIN-01: `PanelDnsResellerApi::request()` resolves the host once per client
  (`resolveHost()`), refuses private/reserved IPv4 before sending and pins cURL with
  `CURLOPT_RESOLVE`. The callers' `gethostbyname()` checks are fail-fast only.
- DOMAIN-SVC-01: `serviceForDomain()` picks the client's DNS service for domain hooks:
  candidates have a sub-client and the option ticked; several → the one with the domain's
  `orderid`, else none (logged). Never `->first()` without a rule.

### Shared files — keep in sync with paneldns-whmcs

The following files exist in BOTH repos and must be kept in sync manually:

| File | Source of truth |
|------|----------------|
| shared/PanelDnsApi.php | paneldns-whmcs |
| shared/LicenceCheck.php | paneldns-whmcs |
| shared/DriftSync.php | paneldns-whmcs |
| shared/WelcomeMail.php | paneldns-whmcs |

When any of these files change in paneldns-whmcs, copy the updated version here and
commit with the message: "sync: update shared/{filename} from paneldns-whmcs"

The copies here differ by design in their class names (`PanelDnsReseller*`, with
`class_exists()` guards). **v1.9.3 changed `shared/` here first** (getLegalVersion against
the reseller endpoint, error/429 parsing, `getAllPages()`, `findSubClientByEmail()`,
redacted transport errors, deep log scrubbing, DriftSync scoped to this module, the
LicenceCheck guard and failure reasons; WelcomeMail's array `customvars` mirrors
paneldns-whmcs PR #1) — reconcile these with paneldns-whmcs. **v1.9.6 changed
`shared/DriftSync.php` (WHMCS as source of truth, 404 handling, test seam) and
`shared/PanelDnsApi.php` (SSRF resolve-once-and-pin, `resolveHost()` seam) here first.**

build.php copies `shared/*.php` into `modules/servers/paneldnsreseller/lib/` (gitignored)
and refuses to build if any `require` leaves the module folder. Module code must only
ever require `lib/` files. The test bootstrap does the same copy and loads the module
through that layout.

## Architecture

- **Module type:** WHMCS Server Module (provisioning)
- **API used:** PanelDNS `/api/v1` (Sanctum Bearer token, reseller-scoped)
- **Auth:** Bearer token stored as encrypted WHMCS server credential
- **No Composer namespaces** — plain PHP, require_once loading

## Key Files

- `modules/servers/paneldnsreseller/paneldnsreseller.php` — main module file
- `modules/servers/paneldnsreseller/hooks.php` — WHMCS hooks (cron, domain, addon, client edit)
- `modules/servers/paneldnsreseller/lib/PanelDnsResellerService.php` — API service layer
- `modules/servers/paneldnsreseller/lib/PanelDnsResellerHooks.php` — hook logic
- `modules/servers/paneldnsreseller/lib/PanelDnsResellerMigration.php` — module-name migration
- `modules/servers/paneldnsreseller/lib/EmbeddedDnsManager.php` — client area zone/record UI
- `shared/PanelDnsApi.php` — HTTP client for /api/v1
- `shared/LicenceCheck.php` — licence verification
- `shared/DriftSync.php` — daily drift sync logic
- `shared/WelcomeMail.php` — welcome email helper

## PanelDNS API compatibility

Compatible with **PanelDNS v3.5.x and later**; checked against the **v3.103.x** API.
Consent stamping needs v3.48.0+ (`GET /api/v1/legal-version`). Tokens minted before
v3.98.0 have no org binding (PanelDNS falls back to the owner's first org) — re-mint them.

API facts the module depends on (re-check when PanelDNS changes):

- `/api/v1/sub-clients` and `/api/v1/zones` page at a **fixed 50** and ignore `per_page`;
  `/zones/{id}/records` honours `per_page` 1–500. Page by `meta.total` (`getAllPages()`).
- `POST /zones/{id}/import` takes `zone_file` (max 51,200) and returns `data: null`.
- `DELETE /sub-clients/{id}` deletes only the sub-client row; its zones keep resolving as
  org-level zones (FK `nullOnDelete`). Delete them first (`DELETE /zones/{id}`, which
  removes them at the provider). There is no call that suspends a sub-client's zones.
- `PATCH /sub-clients/{id}` accepts only `name`, `status`, `zone_limit`, `max_records`
  (email is fixed; `locale` is not accepted, despite the v3.22.0 column).
- `POST /sub-clients` requires the email unique per org (422 `errors.email`) and a
  password of 12–255 characters if one is sent.
- `GET /legal-version` returns `version` at the top level, not under `data`.
- `SubClientApiResource` has no consent fields (`requires_consent`, `portal_sso_url`).
- Errors: `{ok:false, error}`, Laravel `{message, errors}` (422), bare `{message}` (401,
  the API-off 403, the IP-allowlist 403); 429 carries `Retry-After`. Plan limits 60 / 300 /
  1,200 per minute per org; 60 failed authentications per minute per IP.

## Release Process

GitHub Actions builds `paneldns-reseller-whmcs-X.X.X.zip` on tag push (tests first).
Bump `PANELDNS_RESELLER_MODULE_VERSION` in `paneldnsreseller.php`, the default in
`build.php`, CHANGELOG, README and this table together. There is no CI on pull
requests — run `vendor/bin/phpunit` and `php build.php` locally.
