# Changelog

All notable changes to the PanelDNS Reseller WHMCS Module are documented here.

---

## [1.7.0] — 2026-06-03

### Added

- **DNSSEC card in embedded DNS manager (DNSSEC-01)** — the zone records page now shows a
  DNSSEC Signing card below the nameserver card. It displays the current enabled/disabled
  state, an Enable/Disable toggle button (POST `?a=do-dnssec-toggle`), and — when enabled —
  the DS records that the client must add at their domain registrar to complete the
  chain-of-trust. Algorithm is shown beneath the DS records. Silently hidden when the zone
  has no provider or the provider doesn't support DNSSEC. Requires PanelDNS v3.24+.
- New `doDnssecToggle()` and `fetchDnssecStatus()` methods in `EmbeddedDnsManager`.
- `'do-dnssec-toggle'` added to `ClientAreaAllowedFunctions`.
- `renderRecords()` now calls `GET /api/v1/zones/{zone}/dnssec` and passes `$dnssec` var to
  the template; non-fatal if endpoint returns non-2xx (template hides the card).

### Notes

- Registrar DS record submission is intentionally out of scope — too many registrar API
  variations. The WHMCS module shows the DS records for manual submission. Full registrar
  push remains available via the PanelDNS reseller dashboard.
- Requires `paneldns >= v3.24.0` for the new `/api/v1/zones/{zone}/dnssec` endpoints.

---

## [1.6.0] — 2026-06-03

### Added

- **BIND zone export in embedded DNS manager (EXPORT-01)** — a new "↓ Export (BIND)"
  button appears in the zone records page header. Clicking it calls
  `GET /api/v1/zones/{id}/export`, verifies zone ownership, and streams the BIND-format
  text file as a `{zone}.zone` attachment. Gracefully shows a flash error if the zone
  has no DNS provider configured. New `zone-export` action wired through
  `EmbeddedDnsManager`, `ClientAreaAllowedFunctions`, and `clientArea()`. Requires
  `PanelDnsApi::request()` to include `raw_body` in its return (non-JSON endpoint).
- **Client locale sync on `ClientEdit` (LOCALE-01)** — when a reseller's WHMCS client
  updates their profile, the module now includes a `locale` field in the
  `PATCH /api/v1/sub-clients/{id}` call mapped from `tblclients.language`. The
  PanelDNS portal renders in the client's WHMCS language automatically. Mapping: english→en,
  spanish→es, french→fr, german→de, portuguese/brazilian→pt, chinese/chinesesimp→zh\_Hans,
  chinesetrad→zh\_Hant. Unmapped languages are ignored. Requires PanelDNS v3.22+.
- **`PanelDnsApi`: `raw_body` in response array** — `request()` now always includes
  `raw_body` in its return. Existing callers are unaffected (they ignore unknown keys).
  Enables the export handler to read text/plain responses without a separate code path.

---

## [1.5.0] — 2026-06-03

### Added

- **`InvoicePaymentSuccess` hook — auto-unsuspend on payment (PAY-01)** — when a
  reseller's WHMCS client pays an overdue invoice, all their Suspended `paneldns-reseller`
  services are unsuspended in PanelDNS immediately (previously up to 24 hours after
  payment via the nightly DriftSync). The WHMCS service status is also mirrored back to
  Active. Implemented in `PanelDnsResellerHooks::onInvoicePaid()`, wired in `hooks.php`.
  Best-effort per service: one failure does not prevent other services being unsuspended.
- **Nameservers written to WHMCS service notes on provisioning (NS-NOTES-01)** — after a
  successful `CreateAccount`, the sub-client's org nameservers are written into the WHMCS
  service notes field. Support staff can see "point your domain here" values without
  logging into PanelDNS. Implemented in
  `PanelDnsResellerService::writeNameserversToServiceNotes()`.
- **Zone quota pre-flight check in embedded DNS manager (QUOTA-01)** — when a client
  tries to create a zone in the WHMCS-embedded manager, the module now calls
  `GET /api/v1/sub-clients/{id}/summary` first and shows a friendly "You've reached your
  zone limit (N/N) — please contact support to upgrade your plan" message if they are
  at capacity. Previously clients saw a generic API error.
- **Nameserver card on DNS records page (NS-CARD-01)** — the green "Your Nameservers"
  card previously shown only on the overview page is now also rendered at the bottom of
  the records management page (`zone-records.tpl`). Nameservers are cached 5 minutes
  per sub-client via `WHMCS\Cache\Store` so browsing between zones doesn't produce
  extra API calls. Implemented via `PanelDnsEmbeddedDnsManager::fetchNameservers()`.

---

## [1.4.1] — 2026-06-03

### Security — full OWASP re-audit (post-1.2.1 surface)

Second full security pass covering all code added since v1.2.1. All findings fixed.
These are new issues — the original v1.2.1 fixes remain in place.

**Critical**

- **`hooks.php` — fatal require path (C-1)** — `require_once __DIR__ . '/lib/DriftSync.php'`
  pointed at a non-existent path; the shared files live in `shared/` (loaded at build time
  into `lib/`, but the path was wrong). Changed to
  `require_once __DIR__ . '/../../../shared/DriftSync.php'` (matching the pattern used for
  `LicenceCheck.php` and `WelcomeMail.php`). Without this fix the `DailyCronJob` hook
  fatal-errored every night and DriftSync never ran.

**High**

- **`PanelDnsResellerService` constructor — SSRF pre-flight (H-2)** — hostname accepted
  without IP validation. Added `gethostbyname($host)` + `filter_var` with
  `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` before any connection is made.
- **`EmbeddedDnsManager` — zone name not validated (H-4)** — zone names forwarded to
  `POST /api/v1/zones` without format or length checks. Added
  `preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9_\-]|\.[a-zA-Z0-9])*$/', $name)` + 253-char limit
  + consecutive-dot check (`!str_contains($name, '..')`).
- **`templates/client/zone-records.tpl` — DOM XSS in JS confirm dialog (H-6)** —
  `{$r.type}` interpolated bare into a JS string literal. Replaced with
  `{$r.type|json_encode}` so any special chars are JSON-escaped before entering JS context.
- **`templates/client/zones-list.tpl` — DOM XSS in JS confirm dialog (H-7)** —
  `{$zone.name|escape}` inside a single-quoted JS string. Replaced with
  `{$zone.name|json_encode}`. `|escape` is HTML-entity encoding; it does not make a string
  safe inside a JS string literal.
- **`templates/client/sso-redirect.tpl` — open redirect via `javascript:` URI (H-8)** —
  `$redirect_url` rendered directly into `href`. Added Smarty scheme guard:
  `{if $redirect_url|substr:0:8 == 'https://'}…{else}#{/if}`.
- **`shared/DriftSync.php` — exception detail leakage (H-9)** — catch blocks passed
  `$e->getMessage()` to `logActivity()`. SQL fragments or internal paths could appear in
  the WHMCS activity log. Changed to `get_class($e) . ' (see module log)'`. Full detail
  still available via `logModuleCall()`.

**Medium**

- **`EmbeddedDnsManager::handle()` — no rate limiting (M-6/M-7)** — client AJAX
  endpoints could be hammered without restriction. Added sliding-window rate limit:
  60 req/min per sub-client via `\WHMCS\Cache\Store`. Cache failures are logged via
  `logModuleCall()` and fail open (logged, not silently bypassed).
- **`EmbeddedDnsManager` — CSRF token not rotated (M-5)** — the session CSRF token
  was never refreshed after a successful mutation. Added `rotateCsrf()` called at the
  end of every successful create/update/delete/import operation.
- **`templates/client/overview.tpl` — unescaped API output (M-2/M-21)** — usage counts
  and plan limits received from the PanelDNS API were output bare. All values now use
  `{|escape}`.
- **`shared/WelcomeMail.php` — PHP object injection via `serialize()` (M-10)** —
  welcome-email merge data packed with `serialize()`. Replaced with
  `base64_encode(json_encode($merge))`. Synced from `paneldns-whmcs/shared/`.
- **`shared/PanelDnsApi.php` — missing WHMCS file guard (M-19)** — no
  `if (!defined('WHMCS')) { die('Access denied.'); }` at the top. Guard added. Synced from
  `paneldns-whmcs/shared/`.
- **`shared/PanelDnsApi.php` — HTTP plaintext warning (M-12)** — Bearer tokens over
  `http://` now log a WARNING via `logModuleCall()`.
- **`EmbeddedDnsManager` — flash messages and API errors uncapped (M-15/M-17)** —
  flash message stored to session without length cap (reflected to browser). API error
  strings forwarded from provider without sanitisation. Flash capped at 512 chars;
  API error strings capped at 256 chars via `apiError()` helper.
- **`paneldns-reseller.php` — `ListAccountsProductField` missing from `MetaData()` (M-18)** —
  `ListAccountsProductField => 'configoption1'` added so WHMCS Sync knows which config
  option carries the plan identifier.
- **`lib/PanelDnsResellerHooks.php` — API error string in `logActivity()` uncapped (M-20)** —
  raw API error string (up to full response body) logged to WHMCS activity log.
  Capped at 200 chars with `substr()`.
- **`PanelDnsResellerService` — exception detail leakage (M-11)** — `testConnection()`
  and all catch blocks now log full detail via `logModuleCall()` and return generic
  user-facing strings only.

**Low**

- **`shared/LicenceCheck.php` — `STALE_HARD_LOCK` too long (H-1)** — stale cached
  licence trusted for 14 days on network failure. Reduced to 2 days. Grace clock now
  starts at `first_past_due_at` (stamped on first `past_due` fetch) so a brief network
  outage never advances the grace window.
- **`EmbeddedDnsManager` — TTL not lower-bounded (M-4)** — clients could submit TTL=0.
  TTL now enforced `max(60, (int)$ttl)`.
- **`PanelDnsResellerService` — grace days not clamped (L-4)** — `configoption10`
  grace-day value cast to int but not range-checked. Now `min(365, max(0, (int)$graceDays))`.

### Compatibility

No new `paneldns` platform API version required. Compatible with PanelDNS v3.5.x+.
Shared files (`PanelDnsApi.php`, `LicenceCheck.php`, `DriftSync.php`, `WelcomeMail.php`)
are in sync with `paneldns-whmcs v0.7.1`.

---

## [1.4.0] — 2026-05-30

### Fixed — Critical

- **Release ZIP was broken** — the GitHub Actions workflow was zipping raw source
  files without copying `shared/*.php` into `lib/`. Any install from a release tag
  would fatal immediately on `require_once __DIR__ . '/LicenceCheck.php'` because
  that file was never in `lib/`. A new `build.php` (mirroring the platform repo's
  approach) now copies all four shared files into `lib/` before zipping. The
  `lib/PanelDnsApi.php` test stub committed in v1.3.1 is removed from tracking and
  gitignored — `build.php` places the real file there at build time.

### Added

- **`build.php`** — copies `shared/*.php` into `lib/`, produces
  `dist/paneldns-reseller-whmcs-{VERSION}.zip`. Run locally with
  `php build.php` or `RELEASE_VERSION=1.4.0 php build.php`.
- **`SMOKE-TEST.md`** — 14-step post-install verification checklist covering
  connectivity, provisioning, welcome email, client area SSO, record CRUD,
  ListAccounts sync, suspend/terminate, and module log hygiene.
- **`workflow_dispatch`** trigger in GitHub Actions release workflow — enables
  manual re-builds without pushing a new tag.
- **`FORCE_JAVASCRIPT_ACTIONS_TO_NODE24`** env var in CI workflow — suppresses
  GitHub Actions Node.js deprecation warnings.
- **`.github/dependabot.yml`** — weekly Dependabot updates for Composer
  dependencies and GitHub Actions versions.

### Changed

- GitHub Actions `release.yml` updated to use `build.php` instead of raw `zip`
  command, ensuring shared files are present in every release ZIP.
- `.gitignore` updated: `lib/PanelDnsApi.php`, `lib/LicenceCheck.php`,
  `lib/DriftSync.php`, `lib/WelcomeMail.php` are now ignored (build artefacts).

---

## [1.3.1] — 2026-05-30

### Fixed

- **Client "Open full DNS Portal" button was broken** — the `?a=sso` action in the
  client area had no handler; clicking it silently re-rendered the overview page.
  Fixed with a dedicated `sso` branch in `clientArea()` that mints a 60-second SSO
  token and redirects via a new `sso-redirect.tpl` Smarty template with a JS guard
  that only navigates to `https://` URLs.
- **`openPortal` dead code removed** — `paneldns_reseller_openPortal()` and the
  corresponding `openPortal()` service method were unreachable since `AdminSingleSignOn()`
  replaced them in v1.3.0. Both removed.
- **`'usage'` removed from `ClientAreaAllowedFunctions`** — it was listed as an
  allowed client area action but had no handler. Removing it avoids WHMCS silently
  routing `?a=usage` requests to the default overview.

### Added

- **`ServiceSingleSignOn()` — client-facing SSO** — adds a "Login to PanelDNS" link
  button to the client's service page in the WHMCS client area (outside the module's
  own template). Mints a 60-second SSO token and redirects the client's browser to
  their authenticated portal session. Complements `AdminSingleSignOn()` (admin-side)
  added in v1.3.0.
- **`ServiceSingleSignOnLabel`** added to `MetaData()`.
- **`UsageUpdate()`** — WHMCS now populates its usage graphs for services on this
  module. Zone count maps to "Disk" and record count maps to "Bandwidth" — the
  standard pattern for DNS modules without actual disk/bandwidth metrics.
- **Welcome email template documented in INSTALL.md** — Step 5 now explains that
  `"PanelDNS Reseller Welcome"` must be manually created in WHMCS Email Templates
  before welcome emails will send. Without the template, provisioning succeeds silently
  and the client receives nothing. Includes the available merge fields and a note that
  the "Resend Welcome Email" admin button can be used as a manual fallback.
- **New test files** — 3 new PHPUnit test files covering security-sensitive pure logic:
  - `PanelDnsApiSecurityTest` — `isPrivateIp()` (all RFC ranges + edge cases) and
    `redactUrl()` (all sensitive param names, case insensitivity, multiple params)
  - `PanelDnsResellerHooksTest` — addon zone count regex (9 cases), grace period date
    comparison logic, grace marker regex parsing
  - `EmbeddedDnsManagerSecurityTest` — record type allowlist (13 allowed + 7 rejected),
    BIND import size cap arithmetic, CSRF token entropy, SSO redirect URL safety guard
  - Test count: 109 tests, 134 assertions (up from 63 tests before this release)
- **`decrypt()` stub added to test bootstrap** — allows `PanelDnsResellerHooks.php`
  to be loaded in the test environment without the WHMCS `decrypt()` function.

---

## [1.3.0] — 2026-05-30

### Added

- **`AdminSingleSignOn()` — proper portal redirect** — replaces the defunct "Open Portal
  as Client" custom button with WHMCS's native `AdminSingleSignOnLabel` / `AdminSingleSignOn()`
  mechanism. The admin now gets a dedicated "Login to PanelDNS as Client" link button in the
  service detail page that mints a 60-second SSO token and redirects their browser directly
  to the sub-client's authenticated portal session. The old custom button was broken — it
  minted the token but discarded the URL.
- **Nameserver card in client area** — the client overview page now permanently shows the
  reseller's configured nameservers (or the org defaults) in a green card. Clients always see
  "point your domains here" without needing to find the welcome email.
- **"Resend Welcome Email" admin button** — new per-service custom button in the WHMCS admin
  service detail page. Mints a fresh SSO token and re-sends the full welcome email. Useful
  when a client deletes or never receives their original welcome email, or for clients who
  were bulk-synced (who receive no welcome email automatically).
- **`ListAccounts()` implementation** — the WHMCS "List Accounts / Sync" tool on the server
  configuration page now works. Paginates through `/api/v1/sub-clients` (up to 5 000 accounts)
  and returns them in WHMCS format. WHMCS compares the returned `uniqueIdentifier` values
  against `tblhosting.dedicatedip` to surface orphaned or unprovisioned services.
- **Client profile sync** — `ClientEdit` WHMCS hook pushes name and email changes to the
  matching PanelDNS sub-client via `PATCH /api/v1/sub-clients/{id}`. Keeps both systems in
  sync when clients update their profile. No-ops silently if the client has no PanelDNS service.
- **WHMCS Configurable Options support** — `CreateAccount` and `ChangePackage` now check
  `$params['configoptions']['Zone Limit']` and `$params['configoptions']['Max Records Per Zone']`
  before falling back to product-level config options. Resellers can create WHMCS Configurable
  Options with those exact names to let customers choose their zone limit at checkout.
- **Zone list in admin service tab** — the admin service detail panel now shows the actual
  zone names (up to 20) alongside the usage counts. Full list visible via the portal.
- **Termination grace period** — new `configoption11` "Termination Grace Period (Days)"
  (default 0 = immediate delete). When set > 0, `TerminateAccount` suspends the sub-client
  instead of deleting it and stores a `[paneldns-grace:YYYY-MM-DD]` marker in the service
  notes. The nightly `DailyCronJob` hook checks for expired markers and hard-deletes the
  sub-client. Terminated services are already excluded from DriftSync so the suspension is
  never reversed before deletion.

### Changed

- `resolveNameservers()` private helper extracted from `sendWelcomeEmail()` — used by
  `sendWelcomeEmail()`, `resendWelcome()`, and the client area nameserver card. Single source
  of truth for NS resolution logic.
- `MetaData()`: `ListAccountsUniqueIdentifierField` corrected from `'serviceid'` to
  `'dedicatedip'` (sub-client IDs are stored there, not in serviceid).

---

## [1.2.1] — 2026-05-30

### Security

Full OWASP audit — all findings fixed:

- **[HIGH] DriftSync broken authentication** (`shared/DriftSync.php`) — `loadServerParams()`
  was returning the raw encrypted `accesshash` from `tblservers` without calling `decrypt()`.
  Every API call made by the daily drift sync silently failed authentication. Fixed to call
  `decrypt()` matching the pattern used in `PanelDnsResellerHooks::apiForServer()`.
- **[MEDIUM] XSS in flash message** (`EmbeddedDnsManager.php`) — user-supplied zone name
  was interpolated directly into the success flash message and stored in `$_SESSION`.
  Fixed with `htmlspecialchars()` before flash storage.
- **[MEDIUM] No record type allowlist** (`EmbeddedDnsManager.php`) — any string was
  accepted as a DNS record type and forwarded to the API. Added an allowlist of 13 standard
  types; invalid types throw `InvalidArgumentException` caught at both call sites.
- **[MEDIUM] Unbounded BIND import payload** (`EmbeddedDnsManager.php`) — no size limit on
  `$_POST['bind']` allowed memory-exhaustion DoS. Capped at 512 KB.
- **[LOW] PII in module logs** (`shared/PanelDnsApi.php`) — `search=email@example.com` query
  parameters appeared in the WHMCS module log. Added `redactUrl()` to strip `search`, `email`,
  `token`, `key`, `password`, and `secret` params before logging.
- **[LOW] Incomplete SSRF guard** (`shared/PanelDnsApi.php`) — `CURLOPT_IPRESOLVE_V4` was
  set but the resolved IP was not verified after the connection. Added post-connect private-IP
  check via `isPrivateIp()` covering RFC 1918, loopback, link-local, and RFC 6598 ranges.
- **[LOW] `@session_start()` error suppression** (`EmbeddedDnsManager.php`) — suppressed
  session misconfiguration errors that would silently disable CSRF protection. Replaced with
  `session_status() === PHP_SESSION_NONE` guard on all four call sites.
- **[LOW] Reactivation URL unescaped in error banner** (`shared/LicenceCheck.php`) — DB-sourced
  URL included verbatim in the licence error string. Fixed with `htmlspecialchars()`.
- **[LOW] SHA1 cache key** (`shared/PanelDnsApi.php`) — `identityHash()` used `sha1()`.
  Replaced with `hash('sha256', …)`.
- **[LOW] Exception messages in activity log** (`lib/PanelDnsResellerHooks.php`, `hooks.php`)
  — raw exception messages (potentially containing PII or SQL fragments) were passed to
  `logActivity()`. Replaced with `get_class($e)` only.

All four `shared/` files synced to `paneldns-whmcs` (source of truth) as required by the
ecosystem shared-file rule.

---

## [1.2.0] — 2026-05-30

### Added

- **Bulk sub-client sync** — a new "Bulk Sync Sub-clients" button on the WHMCS
  Server configuration page (`ServerCustomButtonArray`) iterates all Active and
  Suspended services on that server and ensures each has a matching PanelDNS
  sub-client. The sync is fully idempotent:
  - Services with a sub-client ID already stored in `dedicatedip` are skipped.
  - Services without an ID are looked up by exact email match against
    `GET /api/v1/sub-clients?search={email}`. If found the existing sub-client
    ID is linked (no duplicate created). If not found a new sub-client is
    provisioned.
  - Capped at 200 services per run to prevent PHP timeouts; re-run to continue
    on large installs.
  - Welcome emails are intentionally skipped during bulk sync (clients
    receive them on the next individual CreateAccount run or manually).
- **`PanelDnsApi::searchSubClients()`** — new typed helper wrapping
  `GET /api/v1/sub-clients?search=`. Synced to `paneldns-whmcs/shared/` as
  required by the shared-file sync rule.

---

## [1.1.0] — 2026-05-30

### Added

- **Domain lifecycle automation (DOMAIN-01/02)** — auto-create a DNS zone in
  PanelDNS when a domain is registered or transferred for the client, and
  optionally delete the zone when the domain is deleted. Both behaviours are
  per-product config options (`Auto-Create Zone on Domain Order`, default on;
  `Auto-Delete Zone on Domain Expiry`, default off). Hooks: `AfterRegistrarRegistration`,
  `AfterRegistrarTransfer`, `DomainDelete`.
- **Addon products (ADDON-01)** — selling a product addon named like
  "PanelDNS +10 Zones" raises the sub-client's zone limit by that many zones on
  activation and lowers it on suspension/termination. Hooks: `AddonActivated`,
  `AddonSuspended`, `AddonTerminated`. The extra-zone count is parsed from the
  addon name; falls back to 5 if no number is present.
- **Usage report in admin service detail** — the admin Services tab now shows
  live zones-used/limit and records utilisation with colour-coded progress bars,
  plus last-sync time and server hostname.
- **Zone health widget in client area** — the client overview surfaces any zones
  that are not in an active state so customers see problems at a glance.

### Notes

- Bulk WHMCS-client → sub-client sync was scoped but deferred: it cannot be made
  idempotent without an email-lookup endpoint on the PanelDNS API (repeat runs
  would create duplicate sub-clients), and provisioning DNS for every billing
  client is rarely desired. Will return once the API supports email dedup.

---

## [1.0.0] — 2026-05-30

Initial public release. Extracted from the paneldns-whmcs monorepo and published
as a standalone open-source module.

### Features

- Provision sub-client accounts in PanelDNS when WHMCS clients order
- Suspend / unsuspend / terminate lifecycle hooks
- Change package (update sub-client plan)
- Daily drift sync — keeps WHMCS and PanelDNS status in sync
- Welcome email with portal login link and SSO URL
- Embedded zone list and record management in WHMCS client area
- Licence verification against PanelDNS platform

---
