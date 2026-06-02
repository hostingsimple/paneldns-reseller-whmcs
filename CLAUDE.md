# PanelDNS Reseller WHMCS Module — Development Specification

> **Ecosystem context:** for how this repo fits with the SaaS platform and the platform
> WHMCS module — and the shared-file sync rules — see `ECOSYSTEM.md` in the main
> `paneldns` repo (https://github.com/Veeau/paneldns/blob/master/ECOSYSTEM.md).

## AI Assistant Rules

- **Check / scan / audit / question → report only.** When asked to check, scan, audit, review,
  or answer a question, produce a findings report and stop. Do NOT write code, edit files,
  commit, or push. Wait for explicit instruction before making any change.
- **Explicit instruction required before any write.**
- **README.md must be updated with every release.**

## Version history (summary)

| Version | Date | Notes |
|---|---|---|
| `1.0.0` | 2026-05-30 | Initial public release (split from paneldns-whmcs) |
| `1.1.0` | 2026-05-30 | Domain lifecycle automation, addon products, usage/health widgets |
| `1.2.0` | 2026-05-30 | Bulk sub-client sync, `searchSubClients()` API helper |
| `1.2.1` | 2026-05-30 | Security hardening — first OWASP audit |
| `1.3.0` | 2026-05-30 | AdminSingleSignOn, NS card, resend welcome, ListAccounts, ClientEdit hook, grace period |
| `1.3.1` | 2026-05-30 | SSO redirect fix, ServiceSingleSignOn, UsageUpdate, test suite expansion |
| `1.4.0` | 2026-05-30 | build.php ZIP fix, SMOKE-TEST.md, dependabot, CI improvements |
| `1.4.1` | 2026-06-03 | Security hardening — full OWASP re-audit (post-1.2.1 surface) |

## Overview

A WHMCS provisioning module (server module) that connects a reseller's WHMCS installation
to their PanelDNS account. When a WHMCS client orders the DNS product, this module
provisions them as a sub-client in the reseller's PanelDNS org via the PanelDNS REST API
(`/api/v1`).

**Target audience:** PanelDNS resellers who use WHMCS to manage and bill their clients.

## Relationship to paneldns-whmcs (private repo)

This repo was extracted from `Veeau/paneldns-whmcs` (private) and made public so resellers
can audit and trust the code.

### Shared files — keep in sync with paneldns-whmcs

The following files exist in BOTH repos and must be kept in sync manually:

| File | Source of truth |
|------|----------------|
| shared/PanelDnsApi.php | paneldns-whmcs |
| shared/LicenceCheck.php | paneldns-whmcs |
| shared/DriftSync.php | paneldns-whmcs |
| shared/WelcomeMail.php | paneldns-whmcs |

When any of these files change in paneldns-whmcs, copy the updated version here and
commit with the message: "sync: update shared/{filename} from paneldns-whmcs"

## Architecture

- **Module type:** WHMCS Server Module (provisioning)
- **API used:** PanelDNS `/api/v1` (Sanctum Bearer token, reseller-scoped)
- **Auth:** Bearer token stored as encrypted WHMCS server credential
- **No Composer namespaces** — plain PHP, require_once loading

## Key Files

- `modules/servers/paneldns-reseller/paneldns-reseller.php` — main module file
- `modules/servers/paneldns-reseller/lib/PanelDnsResellerService.php` — API service layer
- `modules/servers/paneldns-reseller/lib/EmbeddedDnsManager.php` — client area zone/record UI
- `shared/PanelDnsApi.php` — HTTP client for /api/v1
- `shared/LicenceCheck.php` — licence verification
- `shared/DriftSync.php` — daily drift sync logic
- `shared/WelcomeMail.php` — welcome email helper

## PanelDNS API compatibility

Compatible with **PanelDNS v3.5.x through v3.23.x**.

### New API capabilities available but not yet used

| PanelDNS version | New capability | Potential enhancement |
|---|---|---|
| v3.22.0 | `paneldns_sub_clients.locale` | Pass WHMCS client language as `locale` on `POST /api/v1/sub-clients` and `PATCH /api/v1/sub-clients/{id}`. Requires mapping WHMCS language strings (`"english"`, `"french"`) → ISO codes (`"en"`, `"fr"`). |

No module update is required to stay compatible with PanelDNS v3.22.x / v3.23.x.

## Release Process

GitHub Actions builds `paneldns-reseller-whmcs-vX.X.X.zip` on tag push.
