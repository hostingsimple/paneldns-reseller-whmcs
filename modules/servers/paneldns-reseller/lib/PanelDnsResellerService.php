<?php

/**
 * PanelDnsResellerService — mirror of PanelDnsPlatformService for the
 * reseller-tier module. Talks to /api/v1 with a per-user Sanctum token
 * instead of /platform/v1 with PLATFORM_API_KEY.
 *
 * Sub-client ID is stored in the WHMCS service's `dedicatedip` field.
 */

if (!defined('WHMCS')) {
    die('Access denied.');
}

require_once __DIR__ . '/PanelDnsApi.php';
// FIX-C1: LicenceCheck and WelcomeMail live in shared/ — the lib/ paths do not exist.
require_once __DIR__ . '/../../../shared/LicenceCheck.php';
require_once __DIR__ . '/../../../shared/WelcomeMail.php';
require_once __DIR__ . '/EmbeddedDnsManager.php';

class PanelDnsResellerService
{
    public static function run(string $action, array $params): string
    {
        try {
            return (new self($params))->{$action}();
        } catch (\Throwable $e) {
            // FIX-C2: never surface exception messages to WHMCS return values — they
            // may contain server hostnames, tokens, or SQL fragments.
            if (function_exists('logModuleCall')) {
                logModuleCall('paneldns-reseller', 'error:' . $action, [], get_class($e) . ': ' . $e->getMessage(), '');
            }
            return 'paneldns-reseller: provisioning error (see module log).';
        }
    }

    /** @var array */ private $params;
    /** @var PanelDnsApi */ private $api;

    private function __construct(array $params)
    {
        $this->params = $params;

        $hostname = $params['serverhostname'] ?? 'localhost';

        // FIX-H4: SSRF pre-flight — reject hostnames that resolve to private/reserved IPs.
        $resolved = gethostbyname($hostname);
        if (
            filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
        ) {
            throw new \RuntimeException('paneldns-reseller: server hostname resolves to a private/reserved IP address.');
        }

        $baseUrl = ($params['serversecure'] ? 'https' : 'http')
            . '://' . $hostname
            . ($params['serverport'] && !in_array((int) $params['serverport'], [80, 443], true)
                ? ':' . (int) $params['serverport'] : '');

        $this->api = new PanelDnsApi(
            $baseUrl,
            $params['serveraccesshash'] ?? '',
            PanelDnsApi::MODE_RESELLER,
            (bool) ($params['serversecure'] ?? true)
        );
    }

    private function subClientId(): ?int
    {
        $v = $this->params['customfields']['paneldns_sub_client_id'] ?? null;
        if (!$v && !empty($this->params['model']?->dedicatedip)) {
            $v = $this->params['model']->dedicatedip;
        }
        return $v ? (int) $v : null;
    }

    private function setSubClientId(int $id): void
    {
        if (!empty($this->params['serviceid'])) {
            try {
                \WHMCS\Database\Capsule::table('tblhosting')
                    ->where('id', $this->params['serviceid'])
                    ->update(['dedicatedip' => (string) $id]);
            } catch (\Throwable $e) { /* swallow */ }
        }
    }

    /**
     * Resolve the nameserver list for this product/service.
     * Prefers per-product NS overrides (configoption4-7); falls back to
     * the org's configured nameservers from /api/v1/org/nameservers.
     *
     * @return string[] Array of NS hostnames, e.g. ['ns1.example.com', 'ns2.example.com']
     */
    private function resolveNameservers(): array
    {
        $overrides = array_values(array_filter([
            trim((string) ($this->params['configoption4'] ?? '')),
            trim((string) ($this->params['configoption5'] ?? '')),
            trim((string) ($this->params['configoption6'] ?? '')),
            trim((string) ($this->params['configoption7'] ?? '')),
        ], fn ($v) => $v !== ''));

        if (!empty($overrides)) return $overrides;

        $ns = $this->api->nameservers();
        if ($ns['ok'] && !empty($ns['data']['nameservers'])) {
            return (array) $ns['data']['nameservers'];
        }
        return [];
    }

    /**
     * NS-NOTES-01: write the nameservers for this product into WHMCS service notes.
     *
     * Gives support staff instant visibility of "point this domain here" without
     * needing to log into PanelDNS. Best-effort: failures are swallowed so a
     * notes-write failure never blocks provisioning.
     */
    private function writeNameserversToServiceNotes(): void
    {
        $serviceId = (int) ($this->params['serviceid'] ?? 0);
        if ($serviceId <= 0) return;

        $ns = $this->resolveNameservers();
        if (empty($ns)) return;

        $note = 'PanelDNS Nameservers:' . PHP_EOL . implode(PHP_EOL, $ns);
        try {
            \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $serviceId)
                ->update(['notes' => $note]);
        } catch (\Throwable $e) { /* swallow — best-effort */ }
    }

    public function createAccount(): string
    {
        // P2.1: bundled-with-PanelDNS-subscription licence check. Only gates
        // CreateAccount — existing services keep working even if the
        // reseller's PanelDNS subscription lapses (so their customers don't
        // lose DNS overnight). 7-day grace for past_due.
        if ($err = PanelDnsLicenceCheck::gateOrError($this->api, PanelDnsLicenceCheck::REQUIRED_MODULE_RESELLER)) {
            return $err;
        }

        if ($existing = $this->subClientId()) {
            $resp = $this->api->patchSubClient($existing, ['status' => 'active']);
            return $resp['ok'] ? 'success' : ($resp['error'] ?? 'Unsuspend failed.');
        }

        $clientName = trim(($this->params['clientsdetails']['firstname'] ?? '') . ' ' . ($this->params['clientsdetails']['lastname'] ?? ''));
        if ($clientName === '') $clientName = $this->params['clientsdetails']['email'] ?? 'unknown';

        // CONF-01: WHMCS Configurable Options (per-order) take precedence over
        // product-level config options so resellers can offer tiered plans at checkout.
        // Set up a Configurable Option named exactly "Zone Limit" on the product to
        // let customers choose at order time.
        $zoneLimit  = !empty($this->params['configoptions']['Zone Limit'])
            ? (int) $this->params['configoptions']['Zone Limit']
            : (int) ($this->params['configoption1'] ?? 5);
        $maxRecords = !empty($this->params['configoptions']['Max Records Per Zone'])
            ? (int) $this->params['configoptions']['Max Records Per Zone']
            : (int) ($this->params['configoption2'] ?? 100);

        $resp = $this->api->createSubClient([
            'name'        => $clientName,
            'email'       => $this->params['clientsdetails']['email'] ?? '',
            'password'    => $this->params['password'] ?: bin2hex(random_bytes(12)),
            'zone_limit'  => $zoneLimit,
            'max_records' => $maxRecords,
            'status'      => 'active',
        ]);

        if (!$resp['ok']) return $resp['error'] ?: 'CreateSubClient failed.';

        $newId = (int) ($resp['data']['id'] ?? 0);
        if ($newId <= 0) return 'CreateSubClient succeeded but no id returned.';

        $this->setSubClientId($newId);

        // NS-NOTES-01: write the assigned nameservers into the WHMCS service
        // notes so support staff can see them without logging into PanelDNS.
        $this->writeNameserversToServiceNotes();

        if (($this->params['configoption3'] ?? 'yes') === 'yes') {
            $this->sendWelcomeEmail($newId);
        }

        return 'success';
    }

    public function suspendAccount(): string
    {
        $id = $this->subClientId();
        if (!$id) return 'No sub-client id.';
        $resp = $this->api->patchSubClient($id, ['status' => 'suspended']);
        return $resp['ok'] ? 'success' : ($resp['error'] ?: 'Suspend failed.');
    }

    public function unsuspendAccount(): string
    {
        $id = $this->subClientId();
        if (!$id) return 'No sub-client id.';
        $resp = $this->api->patchSubClient($id, ['status' => 'active']);
        return $resp['ok'] ? 'success' : ($resp['error'] ?: 'Unsuspend failed.');
    }

    public function terminateAccount(): string
    {
        $id = $this->subClientId();
        if (!$id) return 'success';

        // FIX-L4: clamp grace period to prevent absurdly large values (e.g. 9999 days).
        $graceDays = min(365, max(0, (int) ($this->params['configoption11'] ?? 0)));

        if ($graceDays > 0) {
            // GRACE-01: suspend instead of delete. DailyCronJob processes the
            // actual deletion once the deadline passes. Terminated WHMCS services
            // are excluded from DriftSync so the suspension is not reversed.
            $resp = $this->api->patchSubClient($id, ['status' => 'suspended']);
            if (!$resp['ok']) return $resp['error'] ?: 'Grace-period suspend failed.';

            $deadline = date('Y-m-d', strtotime("+{$graceDays} days"));
            try {
                \WHMCS\Database\Capsule::table('tblhosting')
                    ->where('id', (int) ($this->params['serviceid'] ?? 0))
                    ->update(['notes' => "[paneldns-grace:{$deadline}]"]);
            } catch (\Throwable $e) { /* swallow — grace still applied in PanelDNS */ }
            return 'success';
        }

        $resp = $this->api->deleteSubClient($id);
        return $resp['ok'] ? 'success' : ($resp['error'] ?: 'Terminate failed.');
    }

    public function changePackage(): string
    {
        $id = $this->subClientId();
        if (!$id) return 'No sub-client id.';

        // CONF-01: honour Configurable Options if set (see createAccount).
        $zoneLimit  = !empty($this->params['configoptions']['Zone Limit'])
            ? (int) $this->params['configoptions']['Zone Limit']
            : (int) ($this->params['configoption1'] ?? 5);
        $maxRecords = !empty($this->params['configoptions']['Max Records Per Zone'])
            ? (int) $this->params['configoptions']['Max Records Per Zone']
            : (int) ($this->params['configoption2'] ?? 100);

        $resp = $this->api->patchSubClient($id, [
            'zone_limit'  => $zoneLimit,
            'max_records' => $maxRecords,
        ]);
        return $resp['ok'] ? 'success' : ($resp['error'] ?: 'ChangePackage failed.');
    }

    public function testConnection(): string
    {
        $sum = $this->api->summary();
        if ($sum['ok']) return 'success';
        // FIX-M11/FIX-M2: log the real error but return a generic message to WHMCS UI.
        if (function_exists('logModuleCall')) {
            logModuleCall('paneldns-reseller', 'testConnection:fail', [], $sum['error'] ?? '', '');
        }
        return 'Authentication failed. Check the module log for details.';
    }

    public function resyncStatus(): string
    {
        $id = $this->subClientId();
        if (!$id) return 'No sub-client id.';
        $resp = $this->api->subClientSummary($id);
        return $resp['ok'] ? 'success' : ($resp['error'] ?: '');
    }

    /**
     * ServiceSingleSignOn / client SSO: mint a portal token and return a redirect URL.
     * Called by WHMCS when the client clicks "Login to PanelDNS" on their service page,
     * and also by the clientArea() 'sso' action for the in-template portal button.
     *
     * @return array{success: bool, redirectUrl?: string, errorMsg?: string}
     */
    public static function clientSso(array $params): array
    {
        try {
            $svc = new self($params);
            $id  = $svc->subClientId();
            if (!$id) {
                return ['success' => false, 'errorMsg' => 'Service not provisioned yet.'];
            }
            $resp = $svc->api->mintSubClientSsoToken($id);
            if (!$resp['ok'] || empty($resp['data']['login_url'])) {
                return ['success' => false, 'errorMsg' => $resp['error'] ?? 'SSO token mint failed.'];
            }
            return ['success' => true, 'redirectUrl' => $resp['data']['login_url']];
        } catch (\Throwable $e) {
            // FIX-C2: redact exception detail from SSO error message.
            if (function_exists('logModuleCall')) {
                logModuleCall('paneldns-reseller', 'error:clientSso', [], get_class($e) . ': ' . $e->getMessage(), '');
            }
            return ['success' => false, 'errorMsg' => 'paneldns-reseller: SSO error (see module log).'];
        }
    }

    /**
     * UsageUpdate: return zone/record counts for WHMCS usage graphs.
     * Zone count maps to "disk" and record count to "bandwidth" — the standard
     * pattern for non-hosting modules without actual disk/bandwidth metrics.
     *
     * @return array{success: bool, diskusage?: int, disklimit?: int, bwusage?: int, bwlimit?: int}
     */
    public static function usageUpdate(array $params): array
    {
        try {
            $svc = new self($params);
            $id  = $svc->subClientId();
            if (!$id) return ['success' => false];

            $resp = $svc->api->subClientSummary($id);
            if (!$resp['ok']) return ['success' => false];

            $usage  = $resp['data']['usage']  ?? [];
            $limits = $resp['data']['limits'] ?? [];

            return [
                'success'   => true,
                'diskusage' => (int) ($usage['zones']   ?? 0),
                'disklimit' => (int) ($limits['zones']  ?? 0),
                'bwusage'   => (int) ($usage['records'] ?? 0),
                'bwlimit'   => (int) ($limits['records'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return ['success' => false];
        }
    }

    /**
     * AdminSingleSignOn — mint a portal SSO token and return a redirect URL.
     * WHMCS calls this when the admin clicks the SSO button on the service page
     * and redirects the admin's browser to the returned URL.
     *
     * @return array{success: bool, redirectUrl?: string, errorMsg?: string}
     */
    public static function adminSso(array $params): array
    {
        try {
            $svc = new self($params);
            $id  = $svc->subClientId();
            if (!$id) {
                return ['success' => false, 'errorMsg' => 'Service not provisioned yet.'];
            }

            $resp = $svc->api->mintSubClientSsoToken($id);
            if (!$resp['ok'] || empty($resp['data']['login_url'])) {
                return ['success' => false, 'errorMsg' => $resp['error'] ?? 'SSO token mint failed.'];
            }

            return ['success' => true, 'redirectUrl' => $resp['data']['login_url']];
        } catch (\Throwable $e) {
            // FIX-C2: redact exception detail from admin SSO error message.
            if (function_exists('logModuleCall')) {
                logModuleCall('paneldns-reseller', 'error:adminSso', [], get_class($e) . ': ' . $e->getMessage(), '');
            }
            return ['success' => false, 'errorMsg' => 'paneldns-reseller: SSO error (see module log).'];
        }
    }

    /**
     * Resend the welcome email with a fresh 60-second SSO link.
     * Triggered by the "Resend Welcome Email" admin custom button.
     * Uses the same send path as the post-CreateAccount welcome email.
     */
    public function resendWelcome(): string
    {
        $id = $this->subClientId();
        if (!$id) return 'Service not provisioned — cannot send welcome email.';

        $sso = $this->api->mintSubClientSsoToken($id);
        if (!$sso['ok'] || empty($sso['data']['login_url'])) {
            return 'SSO mint failed: ' . ($sso['error'] ?? 'unknown');
        }

        $context = [];
        $sum = $this->api->summary();
        if ($sum['ok']) {
            $context['portal_url'] = $sum['data']['links']['portal'] ?? '';
            $context['org_slug']   = $sum['data']['org']['slug'] ?? '';
        }

        $ns = $this->resolveNameservers();
        if (!empty($ns)) $context['nameservers'] = implode("\n", $ns);

        $soaEmail = trim((string) ($this->params['configoption8'] ?? ''));
        if ($soaEmail !== '') $context['soa_email'] = $soaEmail;

        PanelDnsWelcomeMail::sendReseller((int) $this->params['serviceid'], $sso['data'], $context);
        return 'success';
    }

    /**
     * ListAccounts — return all sub-clients on this server for WHMCS sync.
     * Paginates through /api/v1/sub-clients (100 per page, max 50 pages = 5 000).
     * WHMCS compares the returned uniqueIdentifier values against tblhosting.dedicatedip
     * to surface orphaned or unprovisioned services via the Sync tool.
     */
    public static function listAccounts(array $params): array
    {
        try {
            $svc      = new self($params);
            $accounts = [];
            $page     = 1;
            $perPage  = 100;

            while ($page <= 50) {
                $resp = $svc->api->get('/api/v1/sub-clients', ['page' => $page, 'per_page' => $perPage]);
                if (!$resp['ok']) {
                    // First page failure → hard error; later pages → return partial.
                    return $page === 1
                        ? ['success' => false, 'error' => $resp['error'] ?? 'API request failed']
                        : ['success' => true, 'accounts' => $accounts];
                }

                $items = is_array($resp['data']) ? $resp['data'] : [];
                foreach ($items as $sc) {
                    $accounts[] = [
                        'username'         => $sc['email'] ?? '',
                        'domain'           => $sc['name']  ?? ($sc['email'] ?? ''),
                        'uniqueIdentifier' => (string) ($sc['id'] ?? ''),
                        'package'          => 'Zones: ' . ($sc['zone_limit'] ?? '?'),
                        'suspended'        => ($sc['status'] ?? '') === 'suspended',
                    ];
                }

                if (count($items) < $perPage) break; // last page
                $page++;
            }

            return ['success' => true, 'accounts' => $accounts];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'ListAccounts failed: ' . get_class($e)];
        }
    }

    /**
     * BULK-01: Bulk-sync all WHMCS services on this server to PanelDNS sub-clients.
     *
     * Called from the server-level "Bulk Sync Sub-clients" custom button. Iterates
     * every Active/Suspended tblhosting row on this WHMCS server and ensures a
     * matching PanelDNS sub-client exists:
     *
     *   1. Already has dedicatedip set → skip (already provisioned; no API call).
     *   2. No dedicatedip → search /api/v1/sub-clients?search={email}, filter for
     *      exact email match. If found → store the existing sub-client ID in
     *      dedicatedip (link). If not found → create, store ID (provision).
     *
     * Welcome emails are NOT sent during bulk sync — clients are provisioned
     * silently. They will receive a welcome email the next time an individual
     * CreateAccount runs, or the reseller can trigger one manually.
     *
     * Capped at 200 services per run to prevent PHP timeouts on large installs.
     * Re-run to continue if the cap is hit.
     *
     * @param array $params  WHMCS server params (serverhostname, serveraccesshash, …)
     * @return string  Summary line: "Created: N | Linked: N | Skipped: N [| Errors: …]"
     */
    public static function bulkSyncForServer(array $params): string
    {
        $baseUrl = ($params['serversecure'] ? 'https' : 'http')
            . '://' . ($params['serverhostname'] ?? 'localhost')
            . ($params['serverport'] && !in_array((int) $params['serverport'], [80, 443], true)
                ? ':' . (int) $params['serverport'] : '');

        $api = new PanelDnsApi(
            $baseUrl,
            $params['serveraccesshash'] ?? '',
            PanelDnsApi::MODE_RESELLER,
            (bool) ($params['serversecure'] ?? true)
        );

        // Verify connectivity before touching the DB.
        $ping = $api->ping();
        if (!$ping['ok']) {
            return 'Connection failed: ' . ($ping['error'] ?? 'unknown error');
        }

        $serverId = (int) ($params['serverid'] ?? 0);
        if ($serverId <= 0) {
            return 'No server ID in params.';
        }

        // BULK-02: fetch Active/Suspended services with client + product config in one join.
        try {
            $services = \WHMCS\Database\Capsule::table('tblhosting as h')
                ->join('tblclients as c', 'c.id', '=', 'h.userid')
                ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
                ->where('h.server', $serverId)
                ->whereIn('h.domainstatus', ['Active', 'Suspended'])
                ->select([
                    'h.id as serviceid',
                    'h.userid',
                    'h.dedicatedip',
                    'h.domainstatus',
                    'c.email',
                    'c.firstname',
                    'c.lastname',
                    'p.configoption1 as zone_limit',
                    'p.configoption2 as max_records',
                ])
                ->get()
                ->all();
        } catch (\Throwable $e) {
            // FIX-C2: redact exception message — may contain SQL fragments.
            if (function_exists('logModuleCall')) {
                logModuleCall('paneldns-reseller', 'error:bulkSync:db', [], get_class($e) . ': ' . $e->getMessage(), '');
            }
            return 'DB error — see module log for details.';
        }

        if (empty($services)) {
            return 'No active or suspended services found on this server.';
        }

        $cap      = 200; // BULK-03: hard cap — re-run to process remainder
        $created  = 0;
        $linked   = 0;
        $skipped  = 0;
        $errors   = [];
        $processed = 0;

        foreach ($services as $svc) {
            if ($processed >= $cap) {
                $errors[] = "Cap reached ({$cap} services). Re-run to continue.";
                break;
            }
            $processed++;

            // Already provisioned — nothing to do.
            if (!empty($svc->dedicatedip) && is_numeric(trim((string) $svc->dedicatedip))) {
                $skipped++;
                continue;
            }

            $email = trim((string) ($svc->email ?? ''));
            if ($email === '') {
                $errors[] = "Service #{$svc->serviceid}: client has no email address.";
                continue;
            }

            // Search for an existing sub-client with this exact email.
            $existing = null;
            $search = $api->searchSubClients($email);
            if ($search['ok'] && !empty($search['data'])) {
                foreach ((array) $search['data'] as $sc) {
                    if (isset($sc['email']) && strtolower($sc['email']) === strtolower($email)) {
                        $existing = $sc;
                        break;
                    }
                }
            }

            if ($existing !== null) {
                // BULK-04: link — sub-client exists; just store the ID.
                $existingId = (int) ($existing['id'] ?? 0);
                if ($existingId <= 0) {
                    $errors[] = "Service #{$svc->serviceid}: found sub-client but id is missing.";
                    continue;
                }
                try {
                    \WHMCS\Database\Capsule::table('tblhosting')
                        ->where('id', $svc->serviceid)
                        ->update(['dedicatedip' => (string) $existingId]);
                } catch (\Throwable $e) {
                    $errors[] = "Service #{$svc->serviceid}: link DB update failed.";
                    continue;
                }
                $linked++;
                continue;
            }

            // BULK-05: provision — no sub-client exists; create one.
            $name = trim(($svc->firstname ?? '') . ' ' . ($svc->lastname ?? ''));
            if ($name === '') $name = $email;

            $resp = $api->createSubClient([
                'name'        => $name,
                'email'       => $email,
                // Random password — sub-client must reset via portal.
                // Welcome email intentionally skipped for bulk operations.
                'password'    => bin2hex(random_bytes(12)),
                'zone_limit'  => max(0, (int) ($svc->zone_limit  ?? 5)),
                'max_records' => max(0, (int) ($svc->max_records ?? 100)),
                'status'      => $svc->domainstatus === 'Active' ? 'active' : 'suspended',
            ]);

            if (!$resp['ok']) {
                $errors[] = "Service #{$svc->serviceid} ({$email}): " . ($resp['error'] ?? 'create failed');
                continue;
            }

            $newId = (int) ($resp['data']['id'] ?? 0);
            if ($newId <= 0) {
                $errors[] = "Service #{$svc->serviceid}: created but no id returned.";
                continue;
            }

            try {
                \WHMCS\Database\Capsule::table('tblhosting')
                    ->where('id', $svc->serviceid)
                    ->update(['dedicatedip' => (string) $newId]);
            } catch (\Throwable $e) {
                $errors[] = "Service #{$svc->serviceid}: ID store failed after create.";
            }

            $created++;
        }

        $summary = "Created: {$created} | Linked: {$linked} | Skipped (already provisioned): {$skipped}";
        if (!empty($errors)) {
            $first5 = array_slice($errors, 0, 5);
            $summary .= ' | Errors: ' . implode('; ', $first5);
            if (count($errors) > 5) {
                $summary .= ' … (' . count($errors) . ' total)';
            }
        }
        return $summary;
    }

    /**
     * P2.3: render the admin service-detail panel for the reseller module.
     * Pulls /api/v1/sub-clients/{id}/summary with a 60s cache.
     */
    public static function adminServicesTabFields(array $params): array
    {
        $svc = new self($params);
        $id  = $svc->subClientId();
        if (!$id) {
            return ['PanelDNS' => '<em>Not provisioned yet.</em>'];
        }

        $cacheKey = 'paneldns-sub-summary:' . $id;
        $cached = null;
        try {
            if (class_exists('\\WHMCS\\Cache\\Store')) {
                $cached = (new \WHMCS\Cache\Store)->get($cacheKey);
            }
        } catch (\Throwable $e) { /* swallow */ }

        if (!is_array($cached)) {
            $resp = $svc->api->subClientSummary($id);
            if (!$resp['ok']) {
                return [
                    'PanelDNS Sub-client ID' => htmlspecialchars((string) $id, ENT_QUOTES, 'UTF-8'),
                    'PanelDNS Status'        => '<span style="color:#c00;">⚠ ' . htmlspecialchars($resp['error'] ?: 'Lookup failed', ENT_QUOTES, 'UTF-8') . '</span>',
                ];
            }
            $cached = $resp['data'];
            try {
                if (class_exists('\\WHMCS\\Cache\\Store')) {
                    (new \WHMCS\Cache\Store)->put($cacheKey, $cached, 60);
                }
            } catch (\Throwable $e) { /* swallow */ }
        }

        $sub      = $cached['sub_client'] ?? [];
        $usage    = $cached['usage']      ?? [];
        $limits   = $cached['limits']     ?? [];
        $server   = $cached['server']     ?? [];
        $status   = $sub['status'] ?? 'unknown';
        $colour   = $status === 'active' ? '#0a7' : ($status === 'suspended' ? '#c80' : '#888');

        $moduleVersion = defined('PANELDNS_RESELLER_MODULE_VERSION')
            ? PANELDNS_RESELLER_MODULE_VERSION : 'unknown';

        // Feature 5 — live per-sub-client usage fields.
        // Zone and record bars show a colour-coded utilisation indicator so
        // the operator can spot near-limit accounts at a glance.
        $zonesUsed  = (int) ($usage['zones']   ?? 0);
        $zonesLimit = (int) ($limits['zones']  ?? 0);
        $recsUsed   = (int) ($usage['records'] ?? 0);
        $recsLimit  = (int) ($limits['records'] ?? 0);

        $zonePct = $zonesLimit > 0 ? (int) min(100, round($zonesUsed * 100 / $zonesLimit)) : 0;
        $recsPct  = $recsLimit > 0  ? (int) min(100, round($recsUsed * 100 / $recsLimit))  : 0;

        $zoneBar = $zonesLimit > 0
            ? sprintf(
                ' <span style="display:inline-block;width:80px;height:8px;background:#e5e7eb;border-radius:4px;vertical-align:middle;overflow:hidden;">'
                . '<span style="display:block;height:100%;width:%d%%;background:%s;border-radius:4px;"></span></span>'
                . ' <span style="color:#6b7280;font-size:11px;">%d%%</span>',
                $zonePct,
                $zonePct >= 90 ? '#dc2626' : ($zonePct >= 75 ? '#f59e0b' : '#0891b2'),
                $zonePct
            )
            : '';

        $recsBar = $recsLimit > 0
            ? sprintf(
                ' <span style="display:inline-block;width:80px;height:8px;background:#e5e7eb;border-radius:4px;vertical-align:middle;overflow:hidden;">'
                . '<span style="display:block;height:100%;width:%d%%;background:%s;border-radius:4px;"></span></span>'
                . ' <span style="color:#6b7280;font-size:11px;">%d%%</span>',
                $recsPct,
                $recsPct >= 90 ? '#dc2626' : ($recsPct >= 75 ? '#f59e0b' : '#0891b2'),
                $recsPct
            )
            : '';

        // last_synced_at may live under usage, sub_client, or server depending
        // on the API version — check all three locations.
        $lastSync = $usage['last_synced_at']
            ?? $sub['last_synced_at']
            ?? $server['last_synced_at']
            ?? null;
        $lastSyncDisplay = $lastSync
            ? htmlspecialchars((string) $lastSync, ENT_QUOTES, 'UTF-8')
            : '<span style="color:#9ca3af;">—</span>';

        // ZONE-LIST-01: fetch up to 20 zone names for this sub-client.
        $zoneNames = '<span style="color:#9ca3af;">—</span>';
        $zonesResp = $svc->api->get('/api/v1/zones', ['sub_client_id' => $id, 'per_page' => 20]);
        if ($zonesResp['ok'] && !empty($zonesResp['data'])) {
            $names = array_map(
                fn ($z) => htmlspecialchars((string) ($z['name'] ?? ''), ENT_QUOTES, 'UTF-8'),
                $zonesResp['data']
            );
            $zoneNames = implode('<br>', $names);
            if (count($names) >= 20) {
                $zoneNames .= '<br><em style="color:#9ca3af;font-size:11px;">… and more</em>';
            }
        }

        return [
            'Module version'         => '<span style="color:#6b7280;font-variant-numeric:tabular-nums;">paneldns-reseller v'
                . htmlspecialchars($moduleVersion, ENT_QUOTES, 'UTF-8') . '</span>',
            'PanelDNS Sub-client ID' => '<strong>' . htmlspecialchars((string) $id, ENT_QUOTES, 'UTF-8') . '</strong>',
            'Status'                 => sprintf(
                '<span style="color:%s; font-weight:600; text-transform:capitalize;">%s</span>',
                $colour,
                htmlspecialchars($status, ENT_QUOTES, 'UTF-8')
            ),
            'Sub-client Email'       => htmlspecialchars((string) ($sub['email'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'Zones used / limit'     => sprintf('%d / %s', $zonesUsed, $zonesLimit > 0 ? $zonesLimit : '∞') . $zoneBar,
            'Records used / limit'   => sprintf('%d / %s', $recsUsed, $recsLimit > 0 ? $recsLimit : '∞') . $recsBar,
            'Last sync'              => $lastSyncDisplay,
            'Zones'                  => $zoneNames,
            'Server'                 => htmlspecialchars(
                ($svc->params['serverhostname'] ?? ''),
                ENT_QUOTES,
                'UTF-8'
            ),
        ];
    }

    public static function clientArea(array $params): array
    {
        // T1.4: dispatch on customAction to either the embedded DNS
        // manager (zones / records / create / import / mutations) or
        // the legacy "overview" template with usage cards + SSO button.
        $action = $params['customAction'] ?? '';

        // SSO-CLIENT-01: handle the in-template "Open full DNS Portal" button.
        // Mints a 60-second SSO token and redirects via a JS template.
        // ServiceSingleSignOn() handles the same flow from WHMCS's own link button.
        if ($action === 'sso') {
            $svc = new self($params);
            $id  = $svc->subClientId();
            if ($id) {
                $resp = $svc->api->mintSubClientSsoToken($id);
                if ($resp['ok'] && !empty($resp['data']['login_url'])) {
                    return [
                        'templatefile' => 'templates/client/sso-redirect',
                        'vars'         => ['redirect_url' => $resp['data']['login_url']],
                    ];
                }
            }
            return [
                'templatefile' => 'templates/client/overview',
                'vars'         => [
                    'paneldns_error'       => 'Could not generate portal login link.',
                    'paneldns_nameservers' => [],
                ],
            ];
        }

        $embeddedActions = [
            'zones', 'records', 'zone-create', 'zone-import',
            'do-zone-create', 'do-zone-import', 'do-zone-delete',
            'do-record-create', 'do-record-update', 'do-record-delete',
        ];
        if (in_array($action, $embeddedActions, true)) {
            $svc = new self($params);
            $subClientId = $svc->subClientId();
            if (!$subClientId) {
                return [
                    'templatefile' => 'templates/client/overview',
                    'vars' => ['paneldns_error' => 'Service not provisioned yet.'],
                ];
            }
            $mgr = new PanelDnsEmbeddedDnsManager($svc->api, $subClientId, $params);
            return $mgr->handle($action);
        }

        // Default — render the overview with usage cards.
        $vars = [
            'paneldns_sub_client_id' => $params['customfields']['paneldns_sub_client_id']
                ?? $params['model']?->dedicatedip,
            'paneldns_status'      => 'unknown',
            'paneldns_usage'       => null,
            'paneldns_limits'      => null,
            'paneldns_error'       => null,
            'paneldns_nameservers' => [], // NS-01: populated below once API is available
        ];

        $svc = new self($params);
        $id  = $svc->subClientId();
        if (!$id) {
            return ['templatefile' => 'templates/client/overview', 'vars' => $vars];
        }

        $cacheKey = 'paneldns-client-sub-summary:' . $id;
        $data = null;
        try {
            if (class_exists('\\WHMCS\\Cache\\Store')) {
                $data = (new \WHMCS\Cache\Store)->get($cacheKey);
            }
        } catch (\Throwable $e) { /* swallow */ }

        if (!is_array($data)) {
            $resp = $svc->api->subClientSummary($id);
            if ($resp['ok']) {
                $data = $resp['data'];
                try {
                    if (class_exists('\\WHMCS\\Cache\\Store')) {
                        (new \WHMCS\Cache\Store)->put($cacheKey, $data, 60);
                    }
                } catch (\Throwable $e) { /* swallow */ }
            } else {
                $vars['paneldns_error'] = $resp['error'] ?: 'Lookup failed';
                return ['templatefile' => 'templates/client/overview', 'vars' => $vars];
            }
        }

        $vars['paneldns_status']      = $data['sub_client']['status'] ?? 'unknown';
        $vars['paneldns_usage']       = $data['usage'] ?? null;
        $vars['paneldns_limits']      = $data['limits'] ?? null;
        // NS-01: always show nameservers so clients know where to point their domains.
        $vars['paneldns_nameservers'] = $svc->resolveNameservers();

        // Feature 6 — zone health widget: fetch up to 20 zones and surface
        // only the ones that are NOT active so the client sees problems first.
        $vars['paneldns_zones_health'] = null;
        try {
            $zonesResp = $svc->api->get('/api/v1/zones', [
                'sub_client_id' => $id,
                'per_page'      => 20,
            ]);
            if ($zonesResp['ok'] && !empty($zonesResp['data'])) {
                $troubled = [];
                foreach ($zonesResp['data'] as $z) {
                    $zStatus = $z['status'] ?? 'active';
                    if ($zStatus !== 'active') {
                        $troubled[] = [
                            'name'   => $z['name'] ?? '',
                            'status' => $zStatus,
                        ];
                    }
                }
                $vars['paneldns_zones_health'] = $troubled ?: null;
            }
        } catch (\Throwable $e) {
            // Non-fatal — health widget simply does not render on error.
        }

        return ['templatefile' => 'templates/client/overview', 'vars' => $vars];
    }

    private function sendWelcomeEmail(int $subClientId): void
    {
        $serviceId = (int) ($this->params['serviceid'] ?? 0);
        if ($serviceId <= 0) return;

        $sso = $this->api->mintSubClientSsoToken($subClientId);
        if (!$sso['ok'] || empty($sso['data']['login_url'])) return;

        // For the reseller-tier portal URL we hit /api/v1/summary which
        // returns links.portal.
        $context = [];
        $sum = $this->api->summary();
        if ($sum['ok']) {
            $context['portal_url'] = $sum['data']['links']['portal'] ?? '';
            $context['org_slug']   = $sum['data']['org']['slug'] ?? '';
        }

        // T2.3 / NS-01: resolveNameservers() prefers per-product overrides
        // (configoption4-7) and falls back to /api/v1/org/nameservers.
        $ns = $this->resolveNameservers();
        if (!empty($ns)) {
            $context['nameservers'] = implode("\n", $ns);
        }

        // SOA email override (configoption8) — passed through as merge field
        // for templates that surface it.
        $soaEmail = trim((string) ($this->params['configoption8'] ?? ''));
        if ($soaEmail !== '') {
            $context['soa_email'] = $soaEmail;
        }

        PanelDnsWelcomeMail::sendReseller($serviceId, $sso['data'], $context);
    }
}
