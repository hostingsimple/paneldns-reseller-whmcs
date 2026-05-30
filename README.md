# PanelDNS Reseller Module for WHMCS

A WHMCS provisioning module that allows hosting companies running PanelDNS to
sell DNS management to their WHMCS clients as sub-client accounts.

## What it does

When a WHMCS client orders a product using this module, it automatically:
- Creates a sub-client account in your PanelDNS reseller org
- Sends them a welcome email with portal login details
- Keeps WHMCS and PanelDNS in sync (suspend, unsuspend, terminate)
- Embeds a zone/record management UI directly in the WHMCS client area

It can also:
- **Auto-create a DNS zone** when the client registers or transfers a domain
  (and optionally remove it when the domain is deleted) — per-product toggles
- **Grant extra zones via product addons** — an addon named like "PanelDNS +10
  Zones" raises the sub-client's zone limit on activation
- **Show live usage** (zones/records vs limit) in the admin service detail tab
- **Flag unhealthy zones** in the client area so customers spot problems early

## Requirements

- WHMCS 8.7+
- PHP 8.2+
- An active PanelDNS reseller account and API token

## Installation

See [INSTALL.md](INSTALL.md) for full setup instructions.

## Part of PanelDNS

This module connects to [PanelDNS](https://paneldns.com) — a white-label DNS
management platform for hosting companies.

## License

MIT
