# Installation Guide — PanelDNS Reseller Module for WHMCS

This guide walks you through installing and configuring the PanelDNS Reseller module
so your WHMCS clients can order DNS hosting as a product.

---

## Before you start

You need:
- A **PanelDNS reseller account** — sign up at [paneldns.com](https://paneldns.com)
- **WHMCS 8.7+** with admin access
- **PHP 8.2+** on your WHMCS server
- The `curl` and `json` PHP extensions (standard on every modern WHMCS host)

---

## Step 1 — Generate a PanelDNS API token

The module authenticates to PanelDNS using a Sanctum Bearer token scoped to your
reseller org. Never share this token.

1. Log in to your PanelDNS reseller dashboard
2. Go to **Settings → API Tokens**
3. Click **Create Token**
4. Give it a name, e.g. `WHMCS Module`
5. Select these six scopes:
   - `sub_clients:read`
   - `sub_clients:write`
   - `zones:read`
   - `zones:write`
   - `records:read`
   - `records:write`
6. Click **Create** — copy the token shown. **It is only displayed once.**

> Store the token somewhere safe (e.g. your password manager). If you lose it,
> revoke the old one and create a new one — existing provisioned clients are
> unaffected.

### Which feature needs which scope

A missing scope makes PanelDNS answer `403 Scope '…' required.` for that feature only.
Licence status, legal version, org summary and nameservers need no scope.

| Feature | Scopes |
|---|---|
| Create / Suspend / Unsuspend / Terminate / Change Package | `sub_clients:write` |
| Create when the client's email already has a sub-client (duplicate-email check) | `sub_clients:read` |
| "Login to PanelDNS" (admin + client SSO), welcome email | `sub_clients:write` |
| Admin service tab, Resync Status, usage graphs, WHMCS Sync (ListAccounts), daily drift sync | `sub_clients:read` (+ `zones:read` for the zone names on the admin tab) |
| Bulk Sync Sub-clients | `sub_clients:read` + `sub_clients:write` |
| Addon products (extra zones) | `sub_clients:read` + `sub_clients:write` |
| Client profile (name) sync | `sub_clients:write` |
| Client area: zone list, zone health, BIND export, DNSSEC status | `zones:read` |
| Client area: add / delete zone, BIND import, DNSSEC on/off | `zones:write` |
| Client area: record list | `records:read` |
| Client area: add / edit / delete records | `records:write` |
| Auto-create zone on domain registration / transfer | `zones:write` |
| Auto-delete zone on domain deletion | `zones:read` + `zones:write` |

> **Tokens created before PanelDNS 3.98.0** carry no organisation binding: PanelDNS
> resolves them to the token owner's *first* organisation. If your PanelDNS login
> belongs to more than one organisation, create a fresh token from inside the
> organisation this WHMCS sells for, and paste it into the server's Access Hash.

### Rate limits

PanelDNS limits API calls per organisation by plan — **Starter 60, Pro 300, Agency
1,200 requests per minute** — shared by every token of the org, and separately 60
*failed* authentications per minute per IP. When a limit is hit the module shows
"PanelDNS rate limit reached (retry in N seconds)" and makes no further calls to that
server until the `Retry-After` time has passed. A client-area page costs 0–2 calls with
the module's caches warm; the Sync tool (ListAccounts) costs one call per 50 sub-clients.

---

## Step 2 — Download and install the module files

1. Go to the [Releases page](https://github.com/hostingsimple/paneldns-reseller-whmcs/releases)
2. Download the latest `paneldns-reseller-whmcs-X.X.X.zip`
3. Extract the ZIP — it contains a `modules/` folder
4. Copy the `modules/` folder into your WHMCS root directory

The final path should be:

```
<whmcs-root>/
└── modules/
    └── servers/
        └── paneldnsreseller/
            ├── paneldnsreseller.php
            ├── hooks.php
            ├── templates/client/*.tpl
            └── lib/
                ├── PanelDnsResellerService.php
                ├── PanelDnsResellerHooks.php
                ├── PanelDnsResellerMigration.php
                ├── EmbeddedDnsManager.php
                ├── PanelDnsApi.php
                ├── LicenceCheck.php
                ├── DriftSync.php
                └── WelcomeMail.php
```

No Composer install and no tables of its own — the module folder is self-contained.
(Upgrading from 1.9.2 or earlier? See [Upgrading](#upgrading) — the folder name changed.)

---

## Step 3 — Add a server in WHMCS

The "server" in WHMCS represents your connection to the PanelDNS API.

1. In WHMCS Admin, go to **Setup → Products/Services → Servers**
2. Click **Add New Server**
3. Fill in:

   | Field | What to enter |
   |---|---|
   | **Name** | Anything descriptive, e.g. `PanelDNS` |
   | **Hostname** | The API domain — see note below |
   | **Secure** | ☑ Tick this (uses HTTPS) |
   | **Username** | Leave blank |
   | **Password** | Leave blank |
   | **Access Hash** | Paste your API token from Step 1 |
   | **Module** | Select **PanelDNS (Reseller — Sub-client DNS Hosting)** (module `paneldnsreseller`) |

4. Click **Save Changes**
5. Click **Test Connection** — you should see a success message. If not, see [Troubleshooting](#troubleshooting).

### Choosing your hostname

The hostname is the domain the module uses for API calls (`/api/v1/*`). It is
**not** the portal your clients log in to — that is configured separately inside
PanelDNS. You have two options:

**Option A — Use the platform domain (simplest)**

Enter the domain your PanelDNS provider gave you, e.g. `app.paneldns.com`.
This always works and requires no additional setup.

**Option B — Use your white-label portal domain (fully branded)**

If you have set up a custom portal domain in PanelDNS (e.g. `dns.yourbrand.com`),
you can use that domain here instead. The API runs on the same application and
responds identically from any domain that resolves to the server.

Requirements:
- The domain must already be configured as your portal domain in **PanelDNS →
  Settings → Portal Domain** before you enter it here. PanelDNS issues a TLS
  certificate for custom domains at that point — if the domain is not yet
  registered there, HTTPS will fail.
- DNS for the domain must point at the PanelDNS server (A/CNAME record).

With Option B, your WHMCS configuration contains no reference to the underlying
platform provider — your clients and your own admin setup see only your brand.

> **Note:** the hostname controls only where WHMCS sends API requests. It has
> no effect on the URL your sub-clients see when they log in to the portal.

---

## Step 4 — Create a product

1. Go to **Setup → Products/Services → Products/Services**
2. Click **Create a New Product**
3. Set the product type to **Hosting Account** and choose a product group
4. On the **Module Settings** tab:
   - **Module Name**: **PanelDNS (Reseller — Sub-client DNS Hosting)** (`paneldnsreseller`)
   - **Server Group**: select the server group containing your PanelDNS server

   Saving the product with this module also makes WHMCS register the module's
   `hooks.php` (daily drift sync, grace-period cron, domain/addon/payment hooks) —
   WHMCS only loads a provisioning module's hooks once a product using it is saved.

5. Still on Module Settings, configure the options:

   | Option | Recommended setting | Notes |
   |---|---|---|
   | Zone Limit | `5` | How many DNS zones this plan allows |
   | Max Records Per Zone | `100` | Record cap per zone |
   | Send Welcome Email | `Yes` | Sends portal login link on provisioning |
   | NS1–NS4 Hostname | *(leave blank)* | Only needed for white-label nameservers |
   | SOA Email | *(leave blank)* | Only needed if you want a custom SOA contact email |
   | Auto-Create Zone on Domain Order | `Yes` | Recommended — zones are created automatically when clients register domains |
   | Auto-Delete Zone on Domain Expiry | `No` | Leave off unless you are sure clients don't need the data |

6. Set your pricing on the **Pricing** tab as normal
7. Click **Save Changes**

### Optional: let customers choose their zone limit at checkout

The module reads WHMCS Configurable Options before falling back to the product-level
Zone Limit. To offer a "choose your plan size" dropdown at order time:

1. **Setup → Products/Services → Configurable Options → Create a New Group**
2. Add an option named exactly **`Zone Limit`** (and optionally
   **`Max Records Per Zone`**)
3. Add the values customers can pick — e.g. `5`, `10`, `25`
4. Link the option group to your product on its **Configurable Options** tab

The customer's selection takes precedence; if no Configurable Option is set, the
product's Module Settings value is used. This is distinct from **addon products**
(Step 9), which *add* extra zones to an existing limit after provisioning — use
Configurable Options for the base plan size, addons for upgrades.

---

## Step 5 — Create the welcome email template

The module sends a welcome email when a client is provisioned, but only if the
WHMCS email template exists. If the template is missing, provisioning succeeds
silently and the client receives nothing — no error is shown.

1. Go to **WHMCS Admin → Setup → Email Templates → Create New Email Template**
2. Set:
   - **Email Type:** Product
   - **Unique Name:** `PanelDNS Reseller Welcome` *(must match exactly)*
   - **Subject:** `Your DNS Account is Ready — {$service_product_name}`
3. In the email body, use these merge fields:

   | Merge field | What it contains |
   |---|---|
   | `{$paneldns_login_url}` | One-time SSO link (valid 60 seconds) |
   | `{$paneldns_portal_url}` | Long-lived portal URL (bookmark this) |
   | `{$paneldns_nameservers}` | Nameservers to set at their registrar |
   | `{$service_domain}` | The client's domain (standard WHMCS field) |

4. Save the template

> **Tip:** If you set **Send Welcome Email** to `No` on the product, no email is sent
> and this template is not required. Admins can still trigger a send manually via the
> **Resend Welcome Email** button on any service.

---

## Step 6 — Test with a real order

Place a test order:

1. Create or use a test client account in WHMCS
2. Order the product you just created
3. In WHMCS Admin, navigate to the order and **Accept** it
4. The service should move to **Active** status

Verify it worked:

- In WHMCS Admin → **Clients → [your test client] → Services**, find the service
  and click **View Details**
- The **Module Commands** tab should show a **PanelDNS Sub-client ID**
- In your PanelDNS dashboard → **Sub-clients**, the new account should appear
- Click **Login to PanelDNS as Client** — you should land in PanelDNS authenticated as
  the sub-client
- The test client's WHMCS client area should show a DNS management panel under the
  product

---

## Step 7 — (Optional) Bulk sync existing WHMCS clients

If you already have WHMCS clients who should have PanelDNS accounts but were
provisioned before this module was installed, use the bulk sync to catch them up.

1. In WHMCS Admin, go to **Setup → Products/Services → Servers**
2. Click **Edit** next to your PanelDNS server
3. Scroll to **Server Functions** and click **Bulk Sync Sub-clients**

The sync will:
- **Skip** services that already have a sub-client ID (already provisioned — safe to
  re-run at any time)
- **Link** services whose client email exactly matches an existing PanelDNS sub-client
  that no other live service uses (no duplicate created). Bulk Sync is your own admin
  action, so it trusts the email match; automatic provisioning (CreateAccount) does not
  — see "This client already has a PanelDNS account" below.
- **Create** sub-clients for services with no match in PanelDNS
- **Stop early** if PanelDNS rate-limits the run — click the button again a minute later

The result is shown immediately, e.g.:
```
Created: 12 | Linked: 3 | Skipped (already provisioned): 47
```

> **Note:** Welcome emails are not sent during bulk sync. If you want clients to
> receive login details, send them manually or trigger a re-send from each service.

> **Large installs:** The sync is capped at 200 services per run to prevent PHP
> timeouts. If you see "Cap reached — re-run to continue", click the button again.

---

## Step 8 — (Optional) White-label nameservers

If you want clients to see your own nameservers (e.g. `ns1.yourbrand.com`) instead
of the PanelDNS defaults:

1. Set up vanity nameservers in your PanelDNS dashboard → **Settings → Nameservers**
2. In WHMCS, edit your product's **Module Settings**
3. Fill in **NS1–NS4 Hostname** with your vanity nameserver hostnames
4. These are shown in the welcome email and in the client area "use these
   nameservers" panel

The sub-client inherits your org's actual nameservers server-side — these fields
only control the display. See the
[PanelDNS Vanity NS docs](https://github.com/Veeau/paneldns/blob/master/docs/VANITY-NS.md)
for provider-specific setup.

---

## Step 9 — (Optional) Addon products for extra zones

You can sell zone-limit upgrades as WHMCS product addons:

1. In WHMCS Admin, go to **Setup → Products/Services → Product Addons**
2. Create a new addon with a name that includes a number and the word "zones" or
   "zone", e.g.:
   - `PanelDNS +10 Zones`
   - `Extra 25 Zones`
   - `DNS Zone Pack (+5)`
3. Link the addon to your PanelDNS product(s)

When a client activates the addon, the module automatically raises their PanelDNS
zone limit by the number in the addon name. Suspending or terminating the addon
lowers it back.

If no number is found in the addon name, 5 extra zones is assumed as a fallback.

---

## Step 10 — (Optional) Automatic zone creation on domain registration

When a client registers or transfers a domain through WHMCS (via any registrar
module), this module can automatically create a matching DNS zone in PanelDNS.

This feature is enabled by default via the **Auto-Create Zone on Domain Order**
product config option. No additional setup is required — the module registers a
WHMCS hook (`AfterRegistrarRegistration`, `AfterRegistrarTransfer`) that fires
after any domain event.

To also auto-delete zones when domains expire, set **Auto-Delete Zone on Domain
Expiry** to `Yes` on the product. This is off by default because zone data may
still be useful to clients even after a domain expires.

---

## Daily drift sync

The module registers a WHMCS cron hook that runs nightly. It:
- Compares the WHMCS service status with the PanelDNS sub-client status
- Corrects any drift (e.g. a sub-client that was manually changed in PanelDNS)
- Logs discrepancies to the WHMCS Activity Log

It covers this module's services only; the operator module (`paneldnsplatform`)
reconciles its own. No setup is required beyond the standard WHMCS
cron — but WHMCS only loads the module's hooks after a product using the module has
been saved once (Step 4).

## Client profile sync

When a client edits their profile, the new **name** is pushed to their PanelDNS
sub-client. The email address is not: PanelDNS keeps a sub-client's email fixed (it
is the sub-client's sign-in identity), and its API does not accept email or language
changes.

---

## Module logs

All API calls are recorded in WHMCS with API keys redacted:

**WHMCS Admin → System Logs → Module Log**

Filter by module name `paneldns` (API calls) or `paneldnsreseller` (module errors) to
see only PanelDNS entries. Each API entry shows the HTTP method, endpoint, response
status, and any error message. Connection errors are logged here in full; WHMCS
screens and the client area only ever show a generic "could not connect" line.

---

## Upgrading

### From 1.9.3 onwards

1. Download the new ZIP from the [Releases page](https://github.com/hostingsimple/paneldns-reseller-whmcs/releases)
2. Extract it over your WHMCS root, overwriting `modules/servers/paneldnsreseller/`
3. No database changes or WHMCS cache clearing needed

### From 1.9.2 or earlier — the module was renamed

Up to 1.9.2 the module folder was `paneldns-reseller` while its functions were named
`paneldns_reseller_*`. WHMCS calls `<folder>_<Function>`, so it could not call them,
and WHMCS module names must be a single lowercase word anyway. From 1.9.3 the folder,
the main file and every function are **`paneldnsreseller`**. The display name in the
module dropdown is unchanged: *PanelDNS (Reseller — Sub-client DNS Hosting)*.

1. Upload the 1.9.3 ZIP — it creates `modules/servers/paneldnsreseller/`.
2. **Delete the old `modules/servers/paneldns-reseller/` folder.**
3. Point existing products and servers at the new name. Either run this SQL against
   the WHMCS database (back it up first):

   ```sql
   UPDATE tblproducts SET servertype = 'paneldnsreseller' WHERE servertype IN ('paneldns-reseller', 'paneldns_reseller');
   UPDATE tblservers SET type = 'paneldnsreseller' WHERE type IN ('paneldns-reseller', 'paneldns_reseller');
   ```

   …or let the module do it: it runs exactly these two updates (through WHMCS's
   database layer) on the next admin page load and at the start of the daily cron,
   once its hooks are loaded. The updates are idempotent and log a line to the
   Activity Log when they change anything.
4. Open each PanelDNS product → **Module Settings**, check that the module shows
   *PanelDNS (Reseller — Sub-client DNS Hosting)*, and click **Save Changes**. This is
   what makes WHMCS load the new folder's `hooks.php` — WHMCS detects a provisioning
   module's hooks only when a product using it is saved. If you skipped the SQL, this
   save also triggers the automatic migration of your other products and servers.
5. Check the API token's scopes against [the table in Step 1](#which-feature-needs-which-scope)
   — `records:read` and `records:write` were missing from earlier versions of this
   guide, and tokens created before PanelDNS 3.98.0 should be re-created.

Sub-client IDs stored on services (`tblhosting.dedicatedip`) are not touched.

---

## Troubleshooting

### Test Connection fails

- Check the **Hostname** field — no `https://`, no trailing slash, no port unless
  PanelDNS runs on a non-standard port
- Check the **Access Hash** — paste the token with no leading/trailing spaces
- Check that the token scopes include `sub_clients:read`
- Check WHMCS Module Log for the exact error response from PanelDNS
- `REST API tokens is turned off on this platform.` — the PanelDNS operator has switched
  the API off for the whole instance; your token works again when they switch it back on
- `This token is restricted to specific IP addresses.` — add your WHMCS server's IP to
  the token's allowlist in PanelDNS (Settings → API Tokens → Edit)

### CreateAccount returns an error

- **"Licence not active"** — your PanelDNS subscription has lapsed. Log in to
  PanelDNS and check your billing status. The module enters a 7-day grace period
  before blocking new provisioning.
- **"Zone limit exceeded"** — your reseller org has hit its own zone ceiling. Upgrade
  your PanelDNS plan or reduce the Zone Limit on your WHMCS product.
- **"This client already has a PanelDNS account (sub-client #N) on service #M"** —
  PanelDNS allows one sub-client per email address in an organisation, so a second
  DNS service for the same client cannot get its own account. Upgrade service #M
  instead, or cancel the new order.
- **"PanelDNS already has a sub-client with this email … no WHMCS service is linked
  to it"** — a sub-client with the client's email exists (e.g. created by hand in
  PanelDNS). It is not linked automatically, because WHMCS does not verify client
  email addresses. If the client owns it, enter the sub-client ID in the service's
  **Dedicated IP** field and run **Create** again (it is re-activated with the
  product's limits), or use **Bulk Sync** (Step 7).
- A re-order by the **same** client after their previous service was terminated is
  linked back to their existing sub-client automatically, and any pending
  grace-period deletion of it is cancelled.
- **"PanelDNS rate limit reached (retry in N seconds)"** — see
  [Rate limits](#rate-limits); run Create again after the wait.

### Client can't log in to the portal

- Click **Login to PanelDNS as Client** on the service in WHMCS Admin — if this works,
  SSO is functioning and the client is using the wrong credentials
- The welcome email contains a one-time SSO link (valid 60 seconds). The client
  should set a password via the portal's **Forgot Password** flow for permanent
  access.

### Zones not created on domain registration

- Verify **Auto-Create Zone on Domain Order** is set to `Yes` on the product
- Confirm the WHMCS module log shows a `AfterRegistrarRegistration` hook firing
- The client must have an **Active** paneldnsreseller service — the hook only fires
  if a provisioned service is found for that client
- The token needs `zones:write`, and WHMCS must have loaded the module's hooks (save
  the product's Module Settings once — Step 4)

### Admin service tab shows "Not provisioned yet"

- The sub-client ID is stored in the service's `Dedicated IP` field. If it's blank
  the service was never successfully provisioned.
- Try clicking **Resync Status** — if that also fails, re-run **Create** from the
  Module Commands tab or use **Bulk Sync** from the server page.

---

## Uninstalling

1. Terminate all active services using this module (optional but recommended — this
   deletes the corresponding PanelDNS sub-clients)
2. Delete the `modules/servers/paneldnsreseller/` directory (and any leftover
   `modules/servers/paneldns-reseller/` from 1.9.2 or earlier) from your WHMCS install
3. Remove any products and server entries linked to this module from WHMCS Admin

The module creates no WHMCS database tables of its own. Sub-client IDs are stored
in the standard `dedicatedip` field of `tblhosting` and are removed when services
are deleted normally.
