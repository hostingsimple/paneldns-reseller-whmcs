# Changelog

All notable changes to the PanelDNS Reseller WHMCS Module are documented here.

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
