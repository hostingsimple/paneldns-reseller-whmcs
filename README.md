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
| Admin terminates | Sub-client deleted (or suspended for a configurable grace period) |
| Admin changes package | Zone and record limits updated |
| Client updates profile | Name synced to PanelDNS automatically (the email stays fixed — it is the sub-client's sign-in identity) |

### Additional features

- **Embedded DNS manager** — clients manage their zones and records directly inside
  the WHMCS client area without leaving your billing portal
- **DNSSEC signing** — clients can enable/disable DNSSEC on each zone from the record
  manager; DS records are displayed for submission at the domain registrar (requires
  PanelDNS 3.24+, silently hidden for older installs or providers without DNSSEC support)
- **BIND zone export and import** — clients can download any zone as a BIND-format
  `.zone` file from the record manager, and import a zone file of up to 50 KB
  (PanelDNS's limit per import)
- **Nameserver card** — the client area always shows the nameservers clients need to
  set at their registrar, so they never need to dig through old welcome emails
- **Auto-create DNS zones** — when a domain is registered or transferred through
  WHMCS, a matching DNS zone is created automatically in PanelDNS
- **Addon products for extra zones** — sell "PanelDNS +10 Zones" addons; activation
  raises the sub-client's zone limit, suspension lowers it back
- **Live usage panel** — the admin service detail page shows zones/records used vs
  limit with colour-coded progress bars and the actual zone names
- **Zone health widget** — clients see any non-active zones flagged at the top of
  their client area so they spot problems immediately
- **Bulk sub-client sync** — a server-level button provisions all existing WHMCS
  clients in one run; idempotent (safe to re-run at any time)
- **Daily drift sync** — a background job keeps WHMCS and PanelDNS statuses in sync
  and catches any divergence between the two systems
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
  existing service" message instead of a bare HTTP 422; a re-order after termination
  is linked back to the client's existing sub-client. An existing sub-client is never
  linked on email alone (see INSTALL.md → Troubleshooting)
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
| Auto-Create Zone on Domain Order | Yes | Automatically create a DNS zone when the client registers or transfers a domain. |
| Auto-Delete Zone on Domain Expiry | No | Remove the DNS zone when a domain is deleted. Disabled by default. |
| Termination Grace Period (Days) | 0 | Days to wait before permanently deleting the sub-client after termination. 0 = delete immediately. Sub-client is suspended during the grace period and hard-deleted nightly once it expires. |

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
| Bulk Sync Sub-clients | Provisions or links all Active/Suspended services on this server to PanelDNS sub-clients in one run |
| List Accounts / Sync | Built-in WHMCS tool — compares all sub-clients returned by the module against WHMCS services to surface orphaned or unprovisioned accounts |

---

## Directory structure

```
modules/servers/paneldnsreseller/      ← what the release ZIP installs
├── paneldnsreseller.php               WHMCS module functions (paneldnsreseller_*)
├── hooks.php                          cron, domain, addon, client-edit, payment hooks
├── templates/client/*.tpl             client-area pages (?customAction=zones, records, …)
└── lib/
    ├── PanelDnsResellerService.php    lifecycle, SSO, admin tab, client area
    ├── PanelDnsResellerHooks.php      hook logic (domain/addon/grace/payment)
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
  cURL is IPv4-only with TLS verification on.
- Network-level error text (hostnames, IPs, TLS detail) stays in the module log; WHMCS
  screens and the client area show a generic line.
- An existing PanelDNS sub-client is never linked to a new order on email alone, and only
  ever within the token's own organisation.

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
