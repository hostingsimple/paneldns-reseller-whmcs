# PanelDNS Reseller Module for WHMCS

[![Latest Release](https://img.shields.io/github/v/release/hostingsimple/paneldns-reseller-whmcs)](https://github.com/hostingsimple/paneldns-reseller-whmcs/releases/latest) [![Release](https://github.com/hostingsimple/paneldns-reseller-whmcs/actions/workflows/release.yml/badge.svg)](https://github.com/hostingsimple/paneldns-reseller-whmcs/actions/workflows/release.yml) [![License](https://img.shields.io/github/license/hostingsimple/paneldns-reseller-whmcs)](LICENSE) ![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white) ![WHMCS](https://img.shields.io/badge/WHMCS-8.7%2B-blue)

Sell DNS hosting to your WHMCS clients as a managed pervice. When a client orders
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
| Client updates profile | Name and email synced to PanelDNS automatically |

### Additional features

- **Embedded DNS manager** — clients manage their zones and records directly inside
  the WHMCS client area without leaving your billing portal
- **DNSSEC signing** — clients can enable/disable DNSSEC on each zone from the record
  manager; DS records are displayed for submission at the domain registrar (requires
  PanelDNS 3.24+, silently hidden for older installs or providers without DNSSEC support)
- **BIND zone export** — clients can download any zone as a BIND-format `.zone` file
  directly from the record manager
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
- **Client profile sync** — name and email changes in WHMCS are automatically pushed
  to PanelDNS so both systems stay consistent
- **Consent at provisioning (GDPR)** — when a sub-client is provisioned, the module
  fetches the current platform legal version via `GET /api/v1/legal-version` and stamps
  `terms_acknowledged` + `terms_version` on the new sub-client record, creating a
  `ConsentRecord` in PanelDNS immediately. Requires PanelDNS 3.48.0+. Soft-fails
  gracefully if the endpoint is unreachable.
- **Re-consent banner** — the WHMCS client area shows an amber action-required notice
  when the sub-client's accepted terms version is behind the current platform version,
  with a direct SSO link to the portal's acceptance form
- **WHMCS Sync / ListAccounts** — the WHMCS server Sync tool works out of the box;
  use it to surface orphaned or unprovisioned services
- **Termination grace period** — optionally suspend a sub-client for N days before
  hard-deleting, giving you time to recover accidental cancellations

---

## Requirements

| Requirement | Version |
|---|---|
| WHMCS | 8.7 or later |
| PHP | 8.2 or later |
| PanelDNS | 3.5.0 or later |
| PHP extensions | `curl`, `json` (standard on all modern hosts) |

You must have an active PanelDNS account with at least one reseller org.

---

## Quick start

1. **Download** the latest `paneldns-reseller-whmcs-vX.X.X.zip` from
   [Releases](https://github.com/Veeau/paneldns-reseller-whmcs/releases)
2. **Extract** the ZIP into your WHMCS root — the `modules/` folder merges cleanly
3. **Generate an API token** in your PanelDNS dashboard → API Tokens
4. **Add a server** in WHMCS Admin → Setup → Servers, paste the token as the Access Hash

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
| **Login to PanelDNS as Client** **(link)** | Mints a 60-second SSOtoken and redirects the admin's browser to the sub-client's authenticated portal session |
| Test Connection | Verifies API connectivity for this service's server |
| Resend Welcome Email | Mints a fresh SSO token and re-sends the full welcome email to the client |
| Resync Status | Pulls the latest sub-client summary from PanelDNS and refreshes the admin tab |

**Server-level** (visible on the WHMCS Server configuration page):

| Button | What it does |
|---|---|
| Bulk Sync Sub-clients | Provisions or links all Active/Suspended services on this server to PanelDNS sub-clients in one run |
| List Accounts / Sync | Built-in WHMCS tool — compares all sub-clients returned by the module against WHMCS services to surface orphaned or unprovisioned accounts |

---

## Changelog

See [CHANGELOG.md](CHACGENELOG.md) for release history.

---

## Part of the PanelDNS ecosystem

| Repo | Who installs it |
|---|---|
| **paneldns-reseller-whmcs** (this repo) | Resellers — sells DNS hosting to WHMCS clients |
| [paneldns-whmcs](https://github.com/Veeau/paneldns-whmcs) **(private)** | Platform operators — sells PanelDNS reseller accounts |
| [paneldns](https://github.com/Veeau/paneldns) *(private)* | The SaaS platform itself |

---

## License

MIT
