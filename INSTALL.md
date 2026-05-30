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
2. Go to **API Tokens** in the sidebar
3. Click **Create Token**
4. Give it a name, e.g. `WHMCS Module`
5. Select these scopes:
   - `sub_clients:read`
   - `sub_clients:write`
   - `zones:read`
   - `zones:write` *(required for auto-zone creation)*
6. Click **Create** — copy the token shown. **It is only displayed once.**

> Store the token somewhere safe (e.g. your password manager). If you lose it,
> revoke the old one and create a new one — existing provisioned clients are
> unaffected.

---

## Step 2 — Download and install the module files

1. Go to the [Releases page](https://github.com/Veeau/paneldns-reseller-whmcs/releases)
2. Download the latest `paneldns-reseller-whmcs-vX.X.X.zip`
3. Extract the ZIP — it contains a `modules/` folder
4. Copy the `modules/` folder into your WHMCS root directory

The final path should be:

```
<whmcs-root>/
└── modules/
    └── servers/
        └── paneldns-reseller/
            ├── paneldns-reseller.php
            ├── hooks.php
            └── lib/
                ├── PanelDnsResellerService.php
                ├── PanelDnsApi.php
                ├── EmbeddedDnsManager.php
                ├── PanelDnsResellerHooks.php
                ├── LicenceCheck.php
                ├── DriftSync.php
                └── WelcomeMail.php
```

No database migrations, no Composer install, no extra steps — the module is
self-contained.

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
   | **Module** | Select `paneldns-reseller` from the dropdown |

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
   - **Module Name**: `paneldns-reseller`
   - **Server Group**: select the server group containing your PanelDNS server

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
- Click **Open Portal as Client** — you should land in PanelDNS authenticated as
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
- **Link** services whose client email matches an existing PanelDNS sub-client (no
  duplicate created)
- **Create** sub-clients for services with no match in PanelDNS

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
- Logs discrepancies to the WHMCS Module Log

No setup is required — it activates automatically once the module files are in place
and WHMCS is running its standard cron job.

---

## Module logs

All API calls are recorded in WHMCS with API keys redacted:

**WHMCS Admin → System Logs → Module Log**

Filter by module name `paneldns` to see only PanelDNS entries. Each entry shows
the HTTP method, endpoint, response status, and any error message.

---

## Upgrading

1. Download the new ZIP from the [Releases page](https://github.com/Veeau/paneldns-reseller-whmcs/releases)
2. Extract and overwrite the `modules/servers/paneldns-reseller/` directory
3. No database changes or WHMCS cache clearing needed

---

## Troubleshooting

### Test Connection fails

- Check the **Hostname** field — no `https://`, no trailing slash, no port unless
  PanelDNS runs on a non-standard port
- Check the **Access Hash** — paste the token with no leading/trailing spaces
- Check that the token scopes include `sub_clients:read`
- Check WHMCS Module Log for the exact error response from PanelDNS

### CreateAccount returns an error

- **"Licence not active"** — your PanelDNS subscription has lapsed. Log in to
  PanelDNS and check your billing status. The module enters a 7-day grace period
  before blocking new provisioning.
- **"Zone limit exceeded"** — your reseller org has hit its own zone ceiling. Upgrade
  your PanelDNS plan or reduce the Zone Limit on your WHMCS product.
- **"Email already taken"** — a sub-client with this email already exists. Use
  **Bulk Sync** (Step 6) to link the existing sub-client rather than creating a
  duplicate.

### Client can't log in to the portal

- Click **Open Portal as Client** on the service in WHMCS Admin — if this works,
  SSO is functioning and the client is using the wrong credentials
- The welcome email contains a one-time SSO link (valid 60 seconds). The client
  should set a password via the portal's **Forgot Password** flow for permanent
  access.

### Zones not created on domain registration

- Verify **Auto-Create Zone on Domain Order** is set to `Yes` on the product
- Confirm the WHMCS module log shows a `AfterRegistrarRegistration` hook firing
- The client must have an **Active** paneldns-reseller service — the hook only fires
  if a provisioned service is found for that client

### Admin service tab shows "Not provisioned yet"

- The sub-client ID is stored in the service's `Dedicated IP` field. If it's blank
  the service was never successfully provisioned.
- Try clicking **Resync Status** — if that also fails, re-run **Create** from the
  Module Commands tab or use **Bulk Sync** from the server page.

---

## Uninstalling

1. Terminate all active services using this module (optional but recommended — this
   deletes the corresponding PanelDNS sub-clients)
2. Delete the `modules/servers/paneldns-reseller/` directory from your WHMCS install
3. Remove any products and server entries linked to this module from WHMCS Admin

The module creates no WHMCS database tables of its own. Sub-client IDs are stored
in the standard `dedicatedip` field of `tblhosting` and are removed when services
are deleted normally.
