# Changelog

All notable changes to the PanelDNS Reseller WHMCS Module are documented here.

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
