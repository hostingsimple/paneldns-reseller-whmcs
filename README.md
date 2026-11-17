# PanelDNS Reseller Module for WHMCS

[![Latest Release](https://img.shields.io/github/v/release/hostingsimple/paneldns-reseller-whmcs)](https://github.com/hostingsimple/paneldns-reseller-whmcs/releases/latest) [![Release](https://github.com/hostingsimple/paneldns-reseller-whmcs/actions/workflows/release.yml/badge.svg)](https://github.com/hostingsimple/paneldns-reseller-whmcs/actions/workflows/release.yml) [![License](https://img.shields.io/github/license/hostingsimple/paneldns-reseller-whmcs)](LICENSE) ![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white) ![WHMCS](https://img.shields.io/badge/WHMCS-8.7%2B-blue)

Sell DNS hosting to your WHMCS clients as a managed service. When a client orders
your DNS product, this module automatically provisions them as a sub-client inside
your [PanelDNS](https://paneldns.com) reseller account — no manual steps required.

---

## What it does

| Lifecycle | What happens |
|---|---|
| Client orders product | Sub-client created in PanelDNS, welcome email sent with portal login |
| Admin suspends service | Sub-client suspended in PanelDNS |
| Admin unsuspends | Sub-client re-activated |
| Client pays an overdue invoice | WHMCS unsuspends a service it suspended for non-payment (Automation Settings → Enable Unsuspension), which re-activates the sub-client. A suspension with any other reason stays |
| Admin terminates | The sub-client's zones are deleted, then the sub-client (or it is suspended for a configurable grace period, and both are deleted when it ends) |
| Admin changes package | Zone and record limits updated (zone limit = plan + active "PanelDNS +N Zones" addons) |
| Client updates profile | Name synced to PanelDNS automatically (the email stays fixed — it is the sub-client's sign-in identity) |

### Additional features

- **Embedded DNS manager** — clients manage their zones and records directly inside
  the WHMCS client area without leaving your billing portal. Available for Active
  services, to the account owner and to users with the **Perform Single Sign-On**
  permission (v1.9.6)
- **DNSSEC signing** — clients can enable/disable DNSSEC on each zone from the record
  manager; DS records are displayed for submission at the domain registrar (requires
  PanelDNS 3.24+, silently hidden for older installs or providers without DNSSEC support)
- **BIND zone export and import** — clients can download any zone as a BIND-format
  `.zone` file from the record manager, and import a zone file of up to 50 KB
  (PanelDNS's limit per import)
- **Nameserver card** — the client area always shows the nameservers clients need to
  set at their registrar, so they never need to dig through old welcome emails
- **Auto-create DNS zones** — when a domain on the client's account is registered
  through WHMCS, or its transfer completes, a matching DNS zone is created automatically
  in PanelDNS
- **Zones only for the client's own domains** — clients can add zones only for domains
  that are Active on their own WHMCS account, unless the product allows others; even
  then, never a name that overlaps another customer's zone (v1.9.5)
- **Addon products for extra zones** — sell addons named exactly `PanelDNS +N Zones`
  (e.g. "PanelDNS +10 Zones"). The zone limit is always the plan's limit plus the active
  zone packs, so activation raises it, suspension lowers it, and unsuspending or a package
  change keeps it right. Other addons never change the limit (v1.9.6)
- **Live usage panel** — the admin service detail page shows zones/records used vs
  limit with colour-coded progress bars and the actual zone names
- **Zone health widget** — clients see any non-active zones flagged at the top of
  their client area so they spot problems immediately
- **Bulk sub-client sync** — a server-level button provisions all existing WHMCS
  clients in one run; idempotent (safe to re-run at any time). A client whose email
  already has a PanelDNS sub-client is listed for review and never linked automatically
- **Daily drift sync** — a background job compares WHMCS and PanelDNS statuses. WHMCS is
  the source of truth: a sub-client re-activated in PanelDNS while WHMCS has the service
  suspended is suspended again, a sub-client suspended or cancelled in PanelDNS is mirrored
  into WHMCS, and one PanelDNS cannot find is flagged for review, never terminated (v1.9.6)
- **Client profile sync** — name changes in WHMCS are pushed to PanelDNS automatically
  (PanelDNS does not accept email or language changes through its API)
- **Consent at provisioning (GDPR)** — when a sub-client is provisioned, the module
  fetches the current platform legal version via `GET /api/v1/legal-version` and stamps
  `terms_acknowledged` + `terms_version` on the new sub-client record, creating a
  `ConsentRecord` in PanelDNS immediately. Requires PanelDNS 3.48.0+. Soft-fails
  gracefully if the endpoint is unreachable.
- **WHMCS Sync / ListAccounts** — the WHMCS server Sync tool works out of the box and
  sees every sub-client (paged 50 at a time, up to 5,000); use it to surface orphaned
  or unprovisioned services
- **One account per client email** — PanelDNS allows one sub-client per email in an
  organisation. A second DNS order by the same client gets a clear "upgrade the
  existing service" message instead of a bare HTTP 422, and every other duplicate-email
  case (including a re-order after termination) fails with a message naming the existing
  sub-client and its service. An existing sub-client is never linked on email — the admin
  links it by hand after checking (see INSTALL.md → Troubleshooting)
- **Readable errors and rate-limit handling** — PanelDNS validation messages, "API
  switched off" and IP-allowlist refusals are shown as PanelDNS words them; a 429 is
  reported with its retry time and no further calls are made until it passes
- **Termination grace period** — optionally suspend a sub-client for N days before
  hard-deleting, giving you time to recover accidental cancellations

---

## Requirements

| Requirement | Version |
|---|---|
| WHMCS | 8.7 or later |
| PHP | 8.2 or later |
| PanelDNS | 3.5.0 or later (consent stamping needs 3.48.0+; checked against the 3.103 API) |
| PHP extensions | `curl`, `json` (standard on all modern hosts) |

You must have an active PanelDNS account with at least one reseller org.

---

## Quick start

1. **Download** the latest `paneldns-reseller-whmcs-X.X.X.zip` from
   [Releases](https://github.com/hostingsimple/paneldns-reseller-whmcs/releases)
2. **Extract** the ZIP into your WHMCS root — it adds `modules/servers/paneldnsreseller/`
3. **Generate an API token** in your PanelDNS dashboard → Settings → API Tokens, with the
   scopes `sub_clients:read`, `sub_clients:write`, `zones:read`, `zones:write`,
   `records:read`, `records:write` (tokens made before PanelDNS 3.98.0 should be re-made)
4. **Add a server** in WHMCS Admin → Setup → Servers, module *PanelDNS (Reseller — Sub-client
   DNS Hosting)*, and paste the token as the Access Hash

> **Upgrading from 1.9.2 or earlier:** the module folder is now `paneldnsreseller` (it was
> `paneldns-reseller`, a name WHMCS cannot call). Delete the old folder and follow
> [INSTALL.md → Upgrading](INSTALL.md#from-192-or-earlier--the-module-was-renamed) — one SQL
> statement per table, or let the module migrate itself, then save the product once.

Full step-by-step instructions: **[INSTALL.md](INSTALL.md)**

---

## Module config options

When creating a WHMCS product with this module, the following per-product settings
are available:

| Option | Default | Description |
|---|---|---|
| Zone Limit | 5 | Maximum DNS zones this sub-client can create. 0 = inherit org plan limit. Can be overridden per-order using a WHMCS Configurable Option named `Zone Limit`. |
| Max Records Per Zone | 100 | Maximum DNS records per zone. 0 = inherit org plan limit. Can be overridden per-order using a WHMCS Configurable Option named `Max Records Per Zone`. |
| Send Welcome Email | Yes | Email the client a portal login link and nameserver details on provisioning. |
| NS1–NS4 Hostname | **(blank)** | Override the nameservers shown in the welcome email and client area for this product (white-label branding). Leave blank to use the org's default nameservers. |
| SOA Email | **(blank)** | Override the SOA contact email shown in the welcome email. |
| Auto-Create Zone on Domain Order | Yes | Automatically create a DNS zone when a domain on the client's account is registered, or its transfer completes (`DomainTransferCompleted`). A transfer that has only been submitted creates nothing. If the client has several DNS services with this on, the one ordered with the domain gets the zone; if none was, nothing is created and the Activity Log says why. |
| Auto-Delete Zone on Domain Expiry | No | Remove the DNS zone when a domain is deleted. Disabled by default. Picks the service the same way as auto-create. |
| Termination Grace Period (Days) | 0 | Days to wait before permanently deleting the sub-client after termination. 0 = delete immediately. Sub-client is suspended during the grace period and hard-deleted nightly once it expires. Its zones are deleted with it; during the grace period they keep resolving. |
| Allow Zones for Domains Not in This Account | **No** | `configoption12`, added in 1.9.5. **Off:** a client can add a zone only for a domain that is Active on their own WHMCS account. **On:** any name, except one that equals, contains or sits inside another customer's zone (or one of your org's own zones). Turn it on only if your customers host domains registered elsewhere. A zone for a domain the client does not own takes over that domain's DNS once it points at your nameservers. |

### Per-order zone limits with WHMCS Configurable Options

To let customers choose their zone limit at checkout, create a WHMCS Configurable
Option on the product named exactly `Zone Limit` with the desired values (e.g.
5 / 10 / 25). The module checks for this option first and falls back to the
product-level Zone Limit only if it is not set.

---

## Admin buttons

**Per-service** (visible on each individual service in WHMCS Admin):

| Button / link | What it does |
|---|---|
| **Login to PanelDNS as Client** **(link)** | Mints a 60-second SSO token and redirects the admin's browser to the sub-client's authenticated portal session |
| Test Connection | Verifies API connectivity for this service's server |
| Resend Welcome Email | Mints a fresh SSO token and re-sends the full welcome email to the client |
| Resync Status | Pulls the latest sub-client summary from PanelDNS and refreshes the admin tab |

**Server-level** (visible on the WHMCS Server configuration page):

| Button | What it does |
|---|---|
| Bulk Sync Sub-clients | Creates PanelDNS sub-clients for all unprovisioned Active/Suspended services on this server in one run; services whose client email already has a sub-client are listed for review, never linked |
| List Accounts / Sync | Built-in WHMCS tool — compares all sub-clients returned by the module against WHMCS services to surface orphaned or unprovisioned accounts |

---

## Directory structure

```
modules/servers/paneldnsreseller/      ← what the release ZIP installs
├── paneldnsreseller.php               WHMCS module functions (paneldnsreseller_*)
├── hooks.php                          cron, domain, addon and client-edit hooks
├── templates/client/*.tpl             client-area pages (?customAction=zones, records, …)
└── lib/
    ├── PanelDnsResellerService.php    lifecycle, SSO, admin tab, client area
    ├── PanelDnsResellerHooks.php      hook logic (domain/addon/grace/client edit)
    ├── PanelDnsResellerMigration.php  pre-1.9.3 module-name migration
    ├── EmbeddedDnsManager.php         in-WHMCS zone + record manager
    └── PanelDnsApi.php, LicenceCheck.php, DriftSync.php, WelcomeMail.php
                                       ← copied from shared/ by build.php (not committed)
shared/                                source of the four copied files
tests/                                 PHPUnit suite (incl. a build-and-load test of the ZIP)
build.php                              builds dist/paneldns-reseller-whmcs-X.Y.Z.zip
```

---

## Security

- The API token is stored in WHMCS's encrypted server Access Hash. Module-log entries are
  scrubbed at any depth: passwords, tokens, secrets and keys are replaced by `[REDACTED]`,
  one-time SSO links lose their `token=`, the secret values are also passed to WHMCS's
  log masking, `?search=` / `?email=` values are redacted from logged URLs, and zone files
  are logged by size only.
- Every client-area zone or record action first checks that the zone belongs to the
  service's own sub-client; record writes are additionally scoped to that zone by PanelDNS.
- Client-area forms carry a per-service CSRF token, rotated after each change; mutations
  are POST-only and the embedded manager is rate-limited to 60 requests per minute.
- Server hostnames that resolve to private or reserved addresses are refused (SSRF), and
  cURL is IPv4-only with TLS verification on. Since 1.9.6 the hostname is looked up once,
  checked before anything is sent, and cURL connects to exactly that address
  (`CURLOPT_RESOLVE`), so a second DNS answer cannot re-point the request.
- Network-level error text (hostnames, IPs, TLS detail) stays in the module log; WHMCS
  screens and the client area show a generic line. Since 1.9.6 the same goes for any
  error PanelDNS answers with HTTP 5xx (for example a DNS server's own error on a zone
  export or import); clients see PanelDNS's 4xx messages (validation, limits) only.
- **Client-area access (v1.9.6).** The portal button and the embedded DNS manager work
  only for an Active service, and only for the account owner or a user with the WHMCS
  **Perform Single Sign-On** permission on that account. Other users see the overview
  without the DNS buttons.
- **Terminate removes the zones (v1.9.6).** PanelDNS deletes only the sub-client when asked
  to, so the module deletes the sub-client's zones first; a failed zone delete keeps the
  sub-client so the run can be repeated.
- **WHMCS is the source of truth for status (v1.9.6).** The drift sync never makes a
  service less restricted than WHMCS has it and never terminates a service because
  PanelDNS answered "not found".
- **Only named zone packs add zones (v1.9.6).** An addon must be named `PanelDNS +N Zones`;
  the limit is recomputed from the plan and the active packs, not adjusted.
- **No account linking by email (v1.9.4).** WHMCS does not verify client email addresses,
  so a service is linked only to the sub-client id PanelDNS issued for it, kept in the
  service's admin-only Dedicated IP field. SSO, suspend, terminate and the embedded DNS
  manager act on that id alone. A duplicate-email order fails with a message instead of
  adopting the existing sub-client, Bulk Sync reports email matches for review instead of
  linking them, and no client-fillable field (such as a product custom field) is read as
  the sub-client id. Linking an existing sub-client is always a per-service admin step.
- The nightly grace-period delete skips a sub-client that a live service now uses.
- **Zones only for the client's own domains (v1.9.5).** All your customers share your
  nameservers, which answer from the most specific zone they hold. So a zone for someone
  else's name, such as `shop.victim.com` or `victim.com` before its owner adds it, takes
  over that name's DNS. **Add Zone** accepts only a domain that is Active on the client's
  own WHMCS account. The service's Domain field does not count, because the client types
  it on the order form. With **Allow Zones for Domains Not in This Account** on, other
  names are allowed, except one that equals, contains or sits inside a zone that is not
  this client's. The check follows every page of the zone list and refuses the name if a
  lookup fails. Auto-create runs only for a domain on the client's account, on
  registration or a *completed* transfer. It never runs when a transfer is only submitted.
  When PanelDNS refuses a name as taken, the client sees only "This zone name is
  unavailable." and the detail goes to the Activity Log, so customers cannot find out which
  names other customers host.
- **A payment never lifts a suspension through the module (v1.9.5).** The module has no
  invoice hook. Up to 1.9.4 it registered one (`InvoicePaymentSuccess`, a name WHMCS does
  not have) that re-activated every suspended service of a client on any payment, abuse
  suspensions included. Unsuspension on payment is left to WHMCS's own Enable Unsuspension,
  which calls the module's Unsuspend only for services WHMCS suspended for non-payment.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for release history.

---

## Part of the PanelDNS ecosystem

| Repo | Who installs it |
|---|---|
| **paneldns-reseller-whmcs** (this repo) | Resellers — sells DNS hosting to WHMCS clients |
| [paneldns-whmcs](https://github.com/Veeau/paneldns-whmcs) **(private)** | Platform operators — sells PanelDNS reseller accounts (module `paneldnsplatform`) |
| [paneldns](https://github.com/Veeau/paneldns) *(private)* | The SaaS platform itself |

---

## License

MIT
