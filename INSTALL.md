# Installation — paneldns-whmcs

## 1. Choose your module(s)

| If you are… | Install |
|---|---|
| The operator running a PanelDNS SaaS, selling reseller accounts via WHMCS | **paneldns-platform** |
| A reseller with a PanelDNS account, selling DNS hosting to your own customers via WHMCS | **paneldns-reseller** |
| **Anyone with either of the above** — want cross-service admin tools (health check, orphan finder, activity log) | **paneldns-whmcs-addon** (optional but recommended) |

A single WHMCS install can host both server modules plus the addon. Install whichever ZIPs you need.

### Activating the addon module

After dropping `paneldns-whmcs-addon-*.zip` contents into your WHMCS:

1. **WHMCS Admin → System Settings → Addon Modules**
2. Find **PanelDNS** in the list, click **Activate**
3. Click **Configure**, set:
   - **Reactivation URL** — your customer billing portal URL (e.g. `https://your-paneldns/billing`). Shown when a reseller's PanelDNS subscription lapses.
   - **Health cache TTL** — leave at 60s default
   - **Service-list cache TTL** — leave at 120s default
4. Set Access Control for the admin roles that should see PanelDNS tools (Full Administrator by default)
5. The tab appears at **Addons → PanelDNS** in the top admin nav

## 2. Drop in the files

Extract the ZIP into your WHMCS install. The structure already matches:

```
<whmcs-root>/
└── modules/
    └── servers/
        └── paneldns-platform/        ← (or paneldns-reseller)
```

## 3. Get the API credentials

### Platform mode

On your PanelDNS server, generate an operator-tier key:

```bash
docker exec paneldns-app-1 php artisan paneldns:platform-keygen
```

Copy the 64-char hex value to `.env`:

```ini
PLATFORM_API_KEY=eef13883…
```

Restart: `docker compose restart app horizon scheduler`.

### Reseller mode

In your PanelDNS dashboard:

1. **Dashboard → API Tokens → Create Token**
2. Name: `WHMCS Module`
3. Scopes: `sub_clients:read`, `sub_clients:write`, `zones:read`
4. Copy the displayed token (shown once).

## 4. Add the WHMCS server

**WHMCS Admin → Setup → Products/Services → Servers → Add New Server**

| Field | Value |
|---|---|
| Name | `PanelDNS Platform` (or whatever you prefer) |
| Hostname | `paneldns.example.com` (no protocol, no path) |
| Username | *(leave blank)* |
| Password | *(leave blank)* |
| **Access Hash** | Your PLATFORM_API_KEY *(platform mode)* or Sanctum token *(reseller mode)* |
| **Secure** | ☑ Tick for HTTPS (recommended) |

Click **Test Connection** — should report success.

## 5. Create a WHMCS product

**Setup → Products/Services → Products/Services → Create**

- **Module**: `paneldns-platform` (or `paneldns-reseller`)
- **Server**: select the one you just added

**Module Settings** tab — fill in:

| Platform mode | Reseller mode |
|---|---|
| PanelDNS Plan ID *(numeric — from /admin/plans on your PanelDNS server)* | Zone Limit *(e.g. 5)* |
| Partner Source *(optional, e.g. wphosting)* | Max Records Per Zone *(e.g. 100)* |
| Send Welcome Email *(yes/no)* | Send Welcome Email *(yes/no)* |

### Optional: per-product vanity nameservers (T2.3, v0.5.0+)

Both modules expose five additional ConfigOptions for white-label /
multi-brand setups:

| Field | Effect |
|---|---|
| `NS1 Hostname` … `NS4 Hostname` | Override the default NS hostnames for orgs / sub-clients provisioned under this product |
| `SOA Email` | Override the default SOA contact email |

- **Platform mode**: pushed into `POST /platform/v1/orgs` at create-time, stored on the org.
- **Reseller mode**: surfaced in the welcome email and client-area "use these nameservers" panel. Sub-clients inherit the parent org's NS records server-side; the override is decorative for billing-brand separation.

Leave blank to inherit the org / platform defaults. Requires **PanelDNS
≥ v3.5.3** on the server side. See
[`paneldns/docs/VANITY-NS.md`](https://github.com/Veeau/paneldns/blob/master/docs/VANITY-NS.md)
for the per-provider reality check on whether vanity NS actually
answers DNS queries (depends on the underlying DNS provider — works
free on PowerDNS / BIND clusters, requires paid addons on Cloudflare /
Route 53).

### Optional: registrar-event auto-zone creation (T2.1, addon v0.4.0+)

If you've activated the `paneldns` addon module, two toggles in **Setup
→ Addon Modules → PanelDNS** control automatic zone creation when ANY
WHMCS registrar module (eNom, ResellerClub, Namecheap, OpenSRS,
Cloudflare-Registrar, etc.) registers a domain for a customer:

| Setting | Default | Effect |
|---|---|---|
| `Auto-create zone on domain registration` | ON | Fires on `AfterRegistrarRegistration` — `POST /api/v1/zones` with the new domain + customer's sub-client ID |
| `Auto-create zone on inbound transfer` | ON | Fires on `AfterRegistrarTransfer` |

Only fires for customers who have an active `paneldns-reseller`
service. Failure mode is best-effort — exceptions log via
`logModuleCall()` and never block the registrar's own success path.

## 6. Smoke test

1. Place a test order against the new product.
2. Approve / mark active in WHMCS Admin.
3. Check the PanelDNS Filament admin (`/admin/orgs` or `/admin/sub-clients`)
   — the new account should appear.
4. Click **"Login to PanelDNS"** in WHMCS client area → should land
   authenticated in PanelDNS.
5. Suspend the service in WHMCS → org/sub-client shows `suspended` in
   PanelDNS within seconds.
6. Unsuspend → status returns to `active`.

## 7. Module logs

WHMCS Admin → System Logs → Module Log. All API calls are logged with
keys redacted.

## Troubleshooting

- **Test Connection fails** — confirm the Access Hash and hostname.
  Check WHMCS Module Log for the exact response.
- **CreateAccount returns "Plan ID is required"** — populate Plan ID
  in the product's Module Settings.
- **License-locked** — if your PanelDNS subscription lapsed, the module
  enters read-only mode after a 7-day grace period.

For deeper docs see the [PanelDNS docs](https://github.com/Veeau/paneldns/tree/master/docs).
