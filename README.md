# PanelDNS Reseller Module for WHMCS

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
| Admin terminates | Sub-client deleted from PanelDNS |
| Admin changes package | Zone and record limits updated |

### Additional features

- **Embedded DNS manager** — clients manage their zones and records directly inside
  the WHMCS client area without leaving your billing portal
- **Auto-create DNS zones** — when a domain is registered or transferred through
  WHMCS, a matching DNS zone is created automatically in PanelDNS
- **Addon products for extra zones** — sell "PanelDNS +10 Zones" addons; activation
  raises the sub-client's zone limit, suspension lowers it back
- **Live usage panel** — the admin service detail page shows zones/records used vs
  limit with colour-coded progress bars
- **Zone health widget** — clients see any non-active zones flagged at the top of
  their client area so they spot problems immediately
- **Bulk sub-client sync** — a server-level button lets you provision all existing
  WHMCS clients in one run; idempotent (safe to run multiple times)
- **Daily drift sync** — a background job keeps WHMCS and PanelDNS statuses in sync
  and catches any divergence between the two systems

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
| Zone Limit | 5 | Maximum DNS zones this sub-client can create. 0 = inherit org plan limit. |
| Max Records Per Zone | 100 | Maximum DNS records per zone. 0 = inherit org plan limit. |
| Send Welcome Email | Yes | Email the client a portal login link and nameserver details on provisioning. |
| NS1–NS4 Hostname | *(blank)* | Override the nameservers shown in the welcome email for this product (white-label branding). Leave blank to use the org's default nameservers. |
| SOA Email | *(blank)* | Override the SOA contact email shown in the welcome email. |
| Auto-Create Zone on Domain Order | Yes | Automatically create a DNS zone when the client registers or transfers a domain. |
| Auto-Delete Zone on Domain Expiry | No | Remove the DNS zone when a domain is deleted. Disabled by default. |

---

## Admin buttons

**Per-service** (visible on each individual service in WHMCS Admin):

| Button | What it does |
|---|---|
| Test Connection | Verifies API connectivity for this service's server |
| Open Portal as Client | Mints a 60-second SSO link and logs into the PanelDNS portal as this sub-client |
| Resync Status | Pulls the latest sub-client summary from PanelDNS and refreshes the admin tab |

**Server-level** (visible on the WHMCS Server configuration page):

| Button | What it does |
|---|---|
| Bulk Sync Sub-clients | Provisions or links all Active/Suspended services on this server to PanelDNS sub-clients in one run |

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for release history.

---

## Part of the PanelDNS ecosystem

| Repo | Who installs it |
|---|---|
| **paneldns-reseller-whmcs** (this repo) | Resellers — sells DNS hosting to WHMCS clients |
| [paneldns-whmcs](https://github.com/Veeau/paneldns-whmcs) *(private)* | Platform operators — sells PanelDNS reseller accounts |
| [paneldns](https://github.com/Veeau/paneldns) *(private)* | The SaaS platform itself |

---

## License

MIT
