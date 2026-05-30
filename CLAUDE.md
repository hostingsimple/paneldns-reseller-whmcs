# PanelDNS Reseller WHMCS Module — Development Specification

## AI Assistant Rules

- **Check / scan / audit / question → report only.** When asked to check, scan, audit, review,
  or answer a question, produce a findings report and stop. Do NOT write code, edit files,
  commit, or push. Wait for explicit instruction before making any change.
- **Explicit instruction required before any write.**
- **README.md must be updated with every release.**

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

## Release Process

GitHub Actions builds `paneldns-reseller-whmcs-vX.X.X.zip` on tag push.
