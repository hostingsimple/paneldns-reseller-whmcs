# PanelDNS Reseller Module for WHMCS

A WHMCS provisioning module that allows hosting companies running PanelDNS to
sell DNS management to their WHMCS clients as sub-client accounts.

## What it does

When a WHMCS client orders a product using this module, it automatically:
- Creates a sub-client account in your PanelDNS reseller org
- Sends them a welcome email with portal login details
- Keeps WHMCS and PanelDNS in sync (suspend, unsuspend, terminate)
- Embeds a zone/record management UI directly in the WHMCS client area

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
