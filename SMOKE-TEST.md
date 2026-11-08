# Smoke Test — PanelDNS Reseller Module

Run through this checklist after every install or upgrade. Each step should
complete without errors before you move to the next.

---

## Prerequisites

- WHMCS 8.7+ with at least one test client account
- PanelDNS reseller account with an API token (see INSTALL.md §1)
- Module installed and server configured (see INSTALL.md §2–4)
- Welcome email template created (see INSTALL.md §5)
- API token with all six scopes (`sub_clients:*`, `zones:*`, `records:*`)

---

## 0 — Upgrade from 1.9.2 or earlier only

1. Confirm `modules/servers/paneldns-reseller/` is **gone** and
   `modules/servers/paneldnsreseller/` exists
2. Run, against the WHMCS database:
   `SELECT COUNT(*) FROM tblproducts WHERE servertype IN ('paneldns-reseller','paneldns_reseller');`
   and the same for `tblservers.type`
3. ✅ Expected: both `0` (after the SQL in INSTALL.md → Upgrading, or after an admin page
   load once the hooks are registered); **Admin → Activity Log** shows
   "PanelDNS Reseller: migrated module name…" if the module did it
4. Open the PanelDNS product → **Module Settings**
5. ✅ Expected: module shows *PanelDNS (Reseller — Sub-client DNS Hosting)*; click
   **Save Changes** once so WHMCS loads the module's hooks

---

## 1 — Server connectivity

1. Go to **Admin → Setup → Products/Services → Servers**
2. Click **Edit** next to your PanelDNS server
3. Click **Test Connection**
4. ✅ Expected: green "Connection successful" banner

---

## 2 — Provision a test service

1. Create or pick a test client account in WHMCS
2. Place an order for the PanelDNS product as that client
3. In WHMCS Admin, approve / accept the order
4. Navigate to the service — status should be **Active**
5. ✅ Expected: service shows "Active"; no error in the Module Log

---

## 3 — Verify sub-client was created in PanelDNS

1. Log in to your PanelDNS dashboard
2. Go to **Sub-clients**
3. ✅ Expected: the test client's email appears in the list with status Active

---

## 4 — Welcome email

1. Check the test client's email inbox (or check WHMCS **Admin → Email Log**)
2. ✅ Expected: "PanelDNS Reseller Welcome" email with a portal login link

If the email is missing: verify the template exists at
**Setup → Email Templates → "PanelDNS Reseller Welcome"** (see INSTALL.md §5).

---

## 5 — Admin service tab

1. Open the service in WHMCS Admin → Clients → [test client] → Services
2. Click the **PanelDNS** tab on the service detail page
3. ✅ Expected: Sub-client ID, Status (Active), Zones used/limit, zone names panel

---

## 6 — Login to PanelDNS as client (AdminSingleSignOn)

1. On the same service detail page, click **Login to PanelDNS as Client**
2. ✅ Expected: browser redirects to PanelDNS and logs in as the test client

---

## 7 — Client area

1. Log in to WHMCS client area as the test client (or use the "Login as Client" button)
2. Open the PanelDNS product page
3. ✅ Expected:
   - Nameserver card shows NS hostnames
   - Usage cards show zones/records (0/limit at this point)
   - "Manage DNS Zones" button links to embedded zone manager

---

## 8 — Client area SSO

1. On the same product page, click **Open full DNS Portal**
2. ✅ Expected: browser redirects to PanelDNS portal authenticated as the test client

---

## 9 — Resend Welcome Email

1. In WHMCS Admin on the service detail page, click **Resend Welcome Email**
2. ✅ Expected: green "success" result; test client receives another welcome email

---

## 10 — Suspend / Unsuspend

1. In WHMCS Admin, suspend the test service
2. ✅ Expected: sub-client status in PanelDNS becomes "suspended"
3. Unsuspend the service
4. ✅ Expected: sub-client status returns to "active"

---

## 11 — Create a zone in the embedded manager

1. In the client area, click **Manage DNS Zones → Add Zone**
2. Enter a test domain name (e.g. `test-paneldns.example.com`)
3. ✅ Expected: zone appears in the list; PanelDNS dashboard shows it too; the browser
   address bar shows `…&customAction=zones` (not `modop=custom`)

---

## 12 — Create a DNS record

1. Click **Manage** next to the zone
2. Add an A record: name `@`, content `1.2.3.4`, TTL 300
3. ✅ Expected: record appears in the table and you stay on that zone's records page; no API error
4. Edit the record's TTL to 600
5. ✅ Expected: "Record updated."; still on the records page

---

## 12b — Import a BIND zone file

1. Click **Import (BIND)**, choose the test zone, paste
   `www 3600 IN A 192.0.2.10`
2. ✅ Expected: "Zone file imported." and the records page lists the imported record
3. Paste more than 50 KB of text and submit
4. ✅ Expected: "Zone file too large (… KB). PanelDNS accepts up to 50 KB per import" —
   and no `POST …/import` entry in the Module Log

---

## 13 — WHMCS Sync (ListAccounts)

1. Go to **Admin → Setup → Servers → [your PanelDNS server] → List Accounts**
2. ✅ Expected: the test sub-client appears in the results and matches the WHMCS service
3. If the org has more than 50 sub-clients: ✅ the count equals the PanelDNS dashboard's
   sub-client count (not 50)

---

## 13b — Second DNS order by the same client

1. Order the PanelDNS product a second time as the same test client and accept it
2. ✅ Expected: Create fails with "This client already has a PanelDNS account
   (sub-client #N) on service #M … Upgrade service #M instead" — not "HTTP 422" — and the
   new service's Dedicated IP stays empty
3. Cancel/delete the second test service

---

## 14 — Terminate

1. Terminate the test service in WHMCS Admin
2. If grace period = 0: ✅ Expected: sub-client deleted from PanelDNS immediately
3. If grace period > 0: ✅ Expected: sub-client shows "suspended" in PanelDNS; deleted
   after the nightly cron passes the deadline

---

## Module log check

After running the above steps, check **Admin → System Logs → Module Log**
and filter by module name `paneldns`:

- ✅ No unexpected errors
- ✅ API calls show redacted Authorization headers (no raw token in logs)
- ✅ `?search=` parameters show `[REDACTED]` (not raw email addresses)
