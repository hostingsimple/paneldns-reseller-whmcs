# Changelog

All notable changes to the PanelDNS Reseller WHMCS Module are documented here.

---

## [1.9.3] — 2026-11-08

### Upgrade notes — the module was renamed

- **Module name is now `paneldnsreseller` (MODULE-NAME-01).** The folder was `paneldns-reseller`
  while every function was `paneldns_reseller_*`; WHMCS calls `<folder>_<Function>`, so it could
  call none of them, and WHMCS module names must be one lowercase word ("Choosing a Name",
  developers.whmcs.com). Folder, main file (`paneldnsreseller.php`), every function prefix and
  every `servertype` filter now use `paneldnsreseller`; the display name is unchanged.
  **Delete the old `modules/servers/paneldns-reseller/` folder** after uploading.
- **Automatic migration.** `lib/PanelDnsResellerMigration.php` rewrites `tblproducts.servertype`
  and `tblservers.type` from `paneldns-reseller` / `paneldns_reseller` to `paneldnsreseller`
  through Capsule — idempotent, run on `AdminAreaPage` behind two existence checks (once per
  request) and at the start of `DailyCronJob`. WHMCS only loads a provisioning module's
  `hooks.php` once a product using it has been saved, so INSTALL.md also gives the two `UPDATE`
  statements and asks you to save each PanelDNS product's Module Settings once.
- The sibling operator module is renamed to `paneldnsplatform` at the same time and now
  reconciles only its own services, so this module's drift sync is scoped to its own too
  (DRIFT-SCOPE-01) — it used to reconcile both modules' services. Every servertype filter
  matches `paneldnsreseller` plus the legacy spellings, so hooks keep working before the
  migration has run; none of them touches a platform-module name.
- **Re-check the API token's scopes** — see INSTALL.md → "Which feature needs which scope".

### Fixed — breaking defects

- **The release ZIP could not load (BUILD-ZIP-01).** Since 1.4.1 `PanelDnsResellerService.php`
  and `hooks.php` required `__DIR__ . '/../../../shared/…'` — i.e. `modules/shared/`, which the
  ZIP does not contain — so the module fataled on load. Everything now requires the `lib/`
  copies that `build.php` produces, and `build.php` refuses to build if any `require` points
  outside the module folder. A new test builds the ZIP, checks every require resolves inside
  it, and loads the module file and `hooks.php` from the extracted ZIP in a fresh PHP process.
- **`getLegalVersion()` was missing from the shipped client (CONSENT-R-01).** It existed only in
  an uncommitted `lib/` copy, and `build.php` overwrites `lib/` from `shared/`, so every
  CreateAccount died with "Call to undefined method". It is now in `shared/PanelDnsApi.php`,
  against `GET /api/v1/legal-version` — which answers `{"ok": true, "version": "…"}` with the
  version at the top level — and soft-fails to `''` so provisioning continues without the
  consent fields.
- **Zone import sent the wrong field (IMPORT-01).** The module posted `bind`; PanelDNS's
  `POST /api/v1/zones/{id}/import` requires `zone_file` (max 51,200), so every import failed
  with 422. It also read a `data.imported` count the endpoint never returns (`data` is `null`).
  The form now sends `zone_file`, caps it at 50 KB with a clear "split the file" message (was
  512 KB, so anything over 50 KB was rejected by PanelDNS anyway), and reports success without
  an invented count. The import page no longer claims the import is additive — PanelDNS hands
  the file to the zone's provider, which may replace existing records.
- **Embedded DNS manager was unreachable (CLIENT-ROUTE-01).** Its links used
  `modop=custom&a=<page>`, which makes WHMCS call a module function per page that never existed
  (and could not, for the hyphenated names), and the dispatcher read `$params['customAction']`,
  which WHMCS never sets. Pages are now selected with `?customAction=<page>` (the WHMCS sample
  module's pattern) and every template link and form uses it; `ClientAreaAllowedFunctions` was
  removed. `do-*` actions now require POST.
- **DNSSEC toggle never ran (DNSSEC-02):** `do-dnssec-toggle` was missing from the client-area
  dispatch list.
- **Admin service tab crashed when a limit was set.** The usage bars' `sprintf` format
  contained a bare `100%;`, which PHP 8 rejects with `ValueError: Unknown format specifier ";"` —
  i.e. on every service with a zone or record limit.
- **Hooks never acted (MODULE-NAME-01).** The domain, addon, client-edit and payment hooks
  filtered on `servertype = 'paneldns_reseller'`, a name no product carried.

### Fixed

- **Paging (PAGING-01).** PanelDNS pages `/api/v1/sub-clients` and `/api/v1/zones` at a fixed 50
  and ignores `per_page`. ListAccounts asked for 100 and stopped on the first short page, so the
  WHMCS Sync tool only ever saw 50 sub-clients; the client-area zone list and import picker
  showed at most 50 zones. A new `getAllPages()` follows `meta.total` (ListAccounts up to 5,000
  sub-clients, a client's zones up to 1,000, records up to 10,000 at 500 per page); a failed page
  makes ListAccounts fail rather than return a partial list. The admin tab shows "… and N more"
  from `meta.total`, and the domain-delete hook looks the zone up with `?name=` across pages.
- **Same email, second service (DUP-EMAIL-01).** PanelDNS requires a sub-client email to be
  unique per org, so CreateAccount failed with a bare "HTTP 422". On that 422 the module now
  looks the email up (exact match, all pages, org id checked against the token's org) and:
  links back automatically only when the sub-client belonged to a **terminated service of the
  same WHMCS client** (re-order after cancellation — the old service's pending grace delete is
  cancelled); otherwise it links nothing and says why — "this client already has a PanelDNS
  account on service #N (one sub-client per email)", "linked to another client's service", or
  "not linked to any service; enter its ID in Dedicated IP and run Create if this client owns
  it". An existing sub-client is never linked on email alone (WHMCS does not verify client
  emails), and Bulk Sync no longer links a sub-client that another live service already uses.
- **Grace-period cron (GRACE-03/04/05).** It had no servertype filter, so it could act on any
  module's Terminated service carrying the marker; it now filters to `paneldnsreseller`. It
  ignored the delete result and cleared the marker even when PanelDNS refused; the marker is now
  cleared only on success (or 404, already gone), and the failure is logged for retry. The
  marker is appended to the service notes instead of overwriting them, and removed on its own.
- **ClientEdit sent fields PanelDNS ignores (CLIENT-SYNC-02).** `PATCH /api/v1/sub-clients/{id}`
  accepts `name`, `status`, `zone_limit`, `max_records`; the hook also sent `email` and `locale`,
  which were silently dropped. It now sends only `name` (capped at 128), for every active or
  suspended service of the client, and logs a failed sync.
- **SSO buttons never redirected (SSO-REDIRECT-01).** `ServiceSingleSignOn` /
  `AdminSingleSignOn` returned `redirectUrl`; WHMCS reads `redirectTo`
  (developers.whmcs.com/provisioning-modules/single-sign-on/).
- **Welcome-email merge fields could arrive empty (MERGE-VARS-01).** `customvars` was sent to
  `SendEmail` as `base64(json_encode(…))`, which is neither of the forms WHMCS documents (an
  array, or `base64(serialize(…))`). It is now a plain array, as in paneldns-whmcs.
- **Secrets in the module log (LOG-SCRUB-01).** Only top-level request keys were redacted, the
  response was logged raw — an SSO `login_url` carries a live one-time token — and the
  replace-vars argument of `logModuleCall()` held key *names* ('password', 'token'), so WHMCS
  masked those words rather than any secret. Requests and responses are now scrubbed at any
  depth (any `*password*`, `*token*`, `*secret*`, `api_key`, `authorization`, `access_hash`,
  `private_key` key, plus `?token=` / `?password=` style parameters inside strings), and
  replace-vars carries the secret *values* (the API key and every value found under such a key).
- **Re-consent banner removed (CONSENT-R-04).** It read `sub_client.requires_consent` and
  `portal_sso_url`, neither of which PanelDNS's sub-client resource returns, so it could never
  show. The README claim is removed with it.
- **Errors (API-ERRORS-01).** Error bodies are parsed in all three PanelDNS shapes: the `{ok,
  error}` envelope, Laravel validation `message` + `errors` (each field's first message), and a
  bare `message` (the "REST API switched off" 403, the IP-allowlist 403, 401). Field errors are
  also returned under `errors`.
- **Rate limits (RATE-429-01).** A 429's `Retry-After` is captured and reported ("PanelDNS rate
  limit reached (retry in N seconds)"); no further calls go to that server+token until it has
  passed, so the drift sync, Bulk Sync (which now stops early) and paging do not hammer a server
  that said slow down.
- **Fewer API calls per page (RATE-CALLS-01).** Org nameservers cached 5 min (the overview used to
  fetch them on every view), org summary 5 min, client overview summary + zone health 60 s
  together, admin-tab zone list cached with the summary, DNSSEC status 5 min (dropped on
  toggle/import), zone ownership memoised per request. The record update/delete no longer GETs
  the record first (PanelDNS scopes the record to the zone itself), and zone create no longer
  makes a quota pre-flight call that read fields the summary never returns — PanelDNS's own 422
  message is shown instead. An overview view is now 0–1 calls with warm caches (was 3–4); a
  record edit is 5 (was 7).
- **SEC-M14: transport errors redacted.** The raw cURL message (hostnames, IPs, TLS detail) is
  kept in the module log only; callers get "Could not connect to PanelDNS (network error N)".
  The licence `failureReason()` no longer echoes it into the admin banner either, while still
  showing PanelDNS's own refusal text for a 4xx — and the banner now shows that reason.
- **CreateAccount paths.** A WHMCS service password shorter than 12 characters (PanelDNS's
  minimum) made CreateAccount fail with 422; it is now omitted and the sub-client signs in by
  SSO. A stored sub-client id that no longer exists upstream (404) is re-provisioned instead of
  failing. Re-running Create on a linked service re-applies the product's limits.
- **Welcome email never sent when ticked (WELCOME-01).** WHMCS stores a ticked yesno option as
  `on`; the check compared against `yes`.
- **Records page after a change (REDIRECT-01).** After adding, editing or deleting a record, or
  toggling DNSSEC, the client was sent to the zone list (the zone id was only in the POST body);
  they now land back on that zone's records.
- The record manager's nameserver card now honours the product's NS1–NS4 overrides, like the
  overview and the welcome email.
- The embedded manager's own rate limit answers with a page, not a bare JSON body.
- `PanelDnsResellerLicenceCheck` gained the `class_exists()` guard the other shared classes got
  in 1.9.1.

### Security

- **SEC-M9 ported from paneldns-whmcs:** the welcome-mail exception log records the exception
  class only (the message can contain the client's email / service ID).
- Zone file content is logged by size only.

### Docs

- INSTALL.md lists the six scopes (adding the missing `records:read` / `records:write`) and a
  per-feature scope table, the plan rate limits, the rename upgrade steps with the SQL, and a
  note that tokens minted before PanelDNS 3.98.0 have no org binding and should be re-minted.
- README: typos ("pervice", "SSOtoken", the broken `CHACGENELOG.md` link), PHP badge 8.2+,
  directory structure and security sections; CLAUDE.md version table brought up to date;
  `build.php` default version 1.9.3.

### Tests

New: ZIP build + self-containment + load test; `getLegalVersion`; import field and 50 KB cap;
migration SQL builder and Capsule path; paging (`getAllPages`, ListAccounts, zone list);
grace-cron filter and success check; error-body parsing and 429 handling; the duplicate-email
policy; SSO `redirectTo`; `customvars` as an array; nested-secret and SSO-token log scrubbing;
yes/no option values. The tests now load the module through its shipped `lib/` layout. Run
locally on PHP 8.5.6 only (the release workflow runs them on 8.2; there is no PR CI).

---

## [1.9.2] — 2026-08-27

### Fixed

- **Past-due grace clock started one cycle late (GRACE-CLOCK-02).** This module already
  measured grace from `first_past_due_at` rather than from cache age, so it was never
  affected by the indefinite-grace bug the other billing modules had. But the `&& $cached`
  guard discarded the timestamp on the *first* past-due observation — when no cache exists
  yet — writing `null`. The next refresh a day later then set it to that later `now`, so the
  window ran **8 days instead of 7**. Simulated day 0→10 with a virtual clock: locked on day
  8 before, day 7 after.
- **`curl_close()` removed** (PHP8-CURL-01). No effect since PHP 8.0, deprecated in PHP 8.5,
  where it emitted a deprecation notice on every API call.

### Note on tags

Releases `1.8.0`, `1.9.0` and `1.9.1` were documented here but never tagged; the newest tag
was `v1.7.1`. Tagging resumes at `v1.9.2` — the three intervening versions remain untagged.

---

## [1.9.1] — 2026-06-16

### Fixed
- **Coexistence with the `paneldns-platform` WHMCS module.** The two modules bundled
  identically-named global classes (`PanelDnsApi`, `PanelDnsDriftSync`, `PanelDnsWelcomeMail`,
  `PanelDnsLicenceCheck`), so installing both on one WHMCS fataled with **"Cannot redeclare
  class"** (both `hooks.php` `require` them at global scope on every page). This module's
  classes are now prefixed **`PanelDnsReseller*`** so they can never collide with the platform
  module, and each shared class is wrapped in a `class_exists()` guard (belt-and-braces against
  the bundled `shared/` + `lib/` copies double-loading). The platform module is unchanged. You
  can now run both — platform to sell reseller accounts, this one to sell DNS to end users.
  (Version define synced from a stale 1.7.2 to the 1.9.x line.)

---

## [1.9.0] — 2026-06-07

### Added

- **Consent at sub-client provisioning (GDPR-LEGAL-02)**: `createAccount()` now passes optional `terms_acknowledged: true` and `terms_version` fields (fetched via `GET /api/v1/legal-version`) in the `POST /api/v1/sub-clients` payload when creating a new sub-client. WHMCS-provisioned sub-clients carry a `ConsentRecord` from day one, matching the platform module behaviour. Soft-fail: if the legal-version endpoint is unreachable, provisioning continues without the consent fields rather than blocking the order.
- **`PanelDnsApi::getLegalVersion()`** updated to call `GET /api/v1/legal-version` (now fully implemented in PanelDNS 3.48.0); returns the version string or empty string on soft-failure.
- **Re-consent banner (CONSENT-R-03)**: The client area overview shows an amber action-required banner when the sub-client's accepted terms version is behind the current platform version (`requires_consent: true` in the summary response). The banner links directly to the sub-client's authenticated portal session via `portal_sso_url` so the client can review and accept without navigating manually.

---

## [1.8.0] — 2026-06-07

### Added

- **Consent at provisioning (GDPR-LEGAL-01)**: `createAccount()` now fetches the current platform legal version via `GET /api/v1/legal-version` and passes `terms_acknowledged: true` and `terms_version` in the sub-client creation payload. WHMCS-provisioned sub-clients are GDPR-covered immediately at account creation, matching the platform module behaviour. Soft-fail: if the legal-version endpoint is unreachable, provisioning continues without the consent fields rather than blocking the order.
- **Re-consent banner (CONSENT-R-02)**: The client area overview now shows an amber action-required banner when the sub-client's accepted terms version is behind the current platform version (`requires_consent: true` in the summary response). The banner links directly to the sub-client's authenticated portal session (via `portal_sso_url`) so the client can review and accept without needing to navigate manually.
- **`PanelDnsApi::getLegalVersion()`**: New method calling `GET /api/v1/legal-version`, returning the version string or empty string on soft-failure. Added to `lib/PanelDnsApi.php` (and the `shared/PanelDnsApi.php` source of truth should be updated to match).

---

## [1.7.2] — 2026-06-06

### Security — OWASP hardening pass

- **H-7: SSRF pre-flight on server connections** — `PanelDnsResellerService::bulkSyncForServer()`, `DriftSync::reconcile()`, and `PanelDnsResellerHooks::apiForServer()` now resolve the configured server hostname with `gethostbyname()` and reject private/reserved IP ranges before making any connection.
- **H-8: Generic error in `resyncStatus()`** — returns `'Resync failed. See module log for details.'` instead of forwarding the raw API `error` field.
- **H-9: Ownership check before size cap in `importZone()`** — zone ownership is now verified before the 512 KB size limit is applied, preventing a size-cap oracle attack on unauthenticated zone IDs.
- **M-8: DriftSync exception messages** — `$e->getMessage()` replaced with `get_class($e)` in both catch blocks.
- **M-14: LicenceCheck generic error** — raw cURL error string removed from the licence-failure reason; replaced with a generic message.
- **DriftSync log sanitization** — API-sourced `$remoteStatus` and `$whmcsStatus` are HTML-escaped before interpolation into the `logActivity()` reason string.
- **hooks.php indentation fix** — `logActivity()` call in the DailyCronJob catch block was unindented (at column 0 inside an `if` block). Corrected.

---

## [1.7.1] — 2026-06-03

### Fixed

- **`shared/PanelDnsApi.php` synced from source of truth** (`paneldns-whmcs`) — comment labels normalised; `parse_url()` fallback in the HTTP warning now uses `'(unknown)'` instead of `$this->baseUrl` (which could expose port numbers in log output).

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
