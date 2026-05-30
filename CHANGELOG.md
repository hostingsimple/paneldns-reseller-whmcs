# Changelog

All notable changes to the PanelDNS Reseller WHMCS Module are documented here.

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
