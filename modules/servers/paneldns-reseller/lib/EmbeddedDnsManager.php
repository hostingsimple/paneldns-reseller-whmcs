<?php

/**
 * EmbeddedDnsManager — T1.4 in-WHMCS DNS management for paneldns-reseller.
 *
 * Renders the in-page zone list, record manager, zone create form, and
 * BIND import form. All operations are scoped to the WHMCS customer's
 * `sub_client_id` (stored in the service's `dedicatedip` field) — so a
 * customer can never see or touch another sub-client's zones even if
 * they manipulate URL parameters.
 *
 * AJAX is intentionally NOT used in the MVP — every mutation is a
 * full-page POST + redirect, like classic WHMCS module flows. Snappier
 * inline edits can be layered on later without changing the API.
 *
 * @package paneldns-whmcs
 */

if (!defined('WHMCS')) {
    die('Access denied.');
}

class PanelDnsEmbeddedDnsManager
{
    /** @var PanelDnsResellerApi */ private $api;
    /** @var int */         private $subClientId;
    /** @var array */       private $params;

    public function __construct(PanelDnsResellerApi $api, int $subClientId, array $params)
    {
        $this->api = $api;
        $this->subClientId = $subClientId;
        $this->params = $params;
    }

    // ── Dispatch ─────────────────────────────────────────────────────────────

    /**
     * Returns either ['templatefile'=>..., 'vars'=>...] (WHMCS renders the .tpl)
     * or an array with a 'redirect' key that the calling layer turns into a header.
     */
    public function handle(string $action): array
    {
        // FIX-M6: rate-limit all requests (read + write) to 60 per minute per service.
        if (class_exists('\WHMCS\Cache\Store')) {
            try {
                $rlStore = new \WHMCS\Cache\Store();
                $rlKey   = 'paneldns_rl_' . $this->subClientId;
                $hits    = (int) $rlStore->get($rlKey);
                if ($hits >= 60) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => 'Rate limit exceeded.']);
                    exit;
                }
                $rlStore->put($rlKey, $hits + 1, 60);
            } catch (\Throwable $e) {
                if (function_exists('logModuleCall')) {
                    logModuleCall('paneldns-reseller', 'rl_cache_fail', [], $e->getMessage(), '');
                }
            }
        }

        // Verify CSRF on mutating requests.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requireCsrf();
        }

        return match ($action) {
            'zones'        => $this->renderZonesList(),
            'records'      => $this->renderRecords(),
            'zone-create'  => $this->renderZoneCreate(),
            'zone-import'  => $this->renderZoneImport(),
            // EXPORT-01: BIND text download — outputs file directly and exits.
            'zone-export'  => $this->doZoneExport(),
            // DNSSEC-01: toggle DNSSEC signing at the DNS provider.
            'do-dnssec-toggle' => $this->doDnssecToggle(),
            // Mutations — these handle the POST then redirect to a list page.
            'do-zone-create'  => $this->doZoneCreate(),
            'do-zone-import'  => $this->doZoneImport(),
            'do-zone-delete'  => $this->doZoneDelete(),
            'do-record-create'=> $this->doRecordCreate(),
            'do-record-update'=> $this->doRecordUpdate(),
            'do-record-delete'=> $this->doRecordDelete(),
            default        => $this->renderZonesList(),
        };
    }

    // ── Page renders ─────────────────────────────────────────────────────────

    private function renderZonesList(): array
    {
        $resp = $this->api->get("/api/v1/zones?sub_client_id={$this->subClientId}&per_page=100");
        return [
            'templatefile' => 'templates/client/zones-list',
            'vars' => [
                'zones'      => $resp['ok'] ? ($resp['data'] ?? []) : [],
                'error'      => $resp['ok'] ? null : ($resp['error'] ?? 'Failed to load zones'),
                'service_id' => (int) $this->params['serviceid'],
                'flash'      => $this->popFlash(),
                'csrf'       => $this->csrfToken(),
            ],
        ];
    }

    private function renderRecords(): array
    {
        $zoneId = (int) ($_GET['zone'] ?? 0);
        if ($zoneId <= 0) return $this->renderZonesList();

        $zone    = $this->fetchOwnZone($zoneId);
        if (!$zone) return $this->withFlash('error', 'Zone not found.', $this->renderZonesList());

        $records = $this->api->get("/api/v1/zones/{$zoneId}/records?per_page=200");

        // DNSSEC-01: fetch DNSSEC status for display. Non-fatal — if the zone has
        // no provider or the provider doesn't support DNSSEC, we pass supported=false
        // and the template hides the DNSSEC card silently.
        $dnssec = $this->fetchDnssecStatus($zoneId);

        return [
            'templatefile' => 'templates/client/zone-records',
            'vars' => [
                'zone'           => $zone,
                'records'        => $records['ok'] ? ($records['data'] ?? []) : [],
                'error'          => $records['ok'] ? null : ($records['error'] ?? 'Failed to load records'),
                'service_id'     => (int) $this->params['serviceid'],
                'flash'          => $this->popFlash(),
                'csrf'           => $this->csrfToken(),
                'edit_record_id' => (int) ($_GET['edit'] ?? 0) ?: null,
                // NS-CARD-01: pass nameservers so the records page can show
                // the "point your domain here" card alongside the record editor.
                'nameservers'    => $this->fetchNameservers(),
                // DNSSEC-01: current signing state + DS records. null if unsupported/no provider.
                'dnssec'         => $dnssec,
            ],
        ];
    }

    private function renderZoneCreate(): array
    {
        return [
            'templatefile' => 'templates/client/zone-create',
            'vars' => [
                'service_id' => (int) $this->params['serviceid'],
                'csrf'       => $this->csrfToken(),
            ],
        ];
    }

    private function renderZoneImport(): array
    {
        return [
            'templatefile' => 'templates/client/zone-import',
            'vars' => [
                'service_id' => (int) $this->params['serviceid'],
                'csrf'       => $this->csrfToken(),
                'zones'      => $this->fetchOwnZones(),
            ],
        ];
    }

    // ── Mutations ────────────────────────────────────────────────────────────

    private function doZoneCreate(): array
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') return $this->withFlash('error', 'Zone name is required.', $this->renderZoneCreate());

        // FIX-H4: validate zone name — 253 char limit, no consecutive dots, RFC-safe chars only.
        if (
            strlen($name) > 253
            || str_contains($name, '..')
            || !preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9_\-]|\.[a-zA-Z0-9])*$/', $name)
        ) {
            return $this->withFlash('error', 'Invalid zone name.', $this->renderZoneCreate());
        }

        // QUOTA-01: pre-flight check before the API call so the client sees a
        // friendly message instead of a generic API error when they're at the limit.
        $summary = $this->api->get('/api/v1/sub-clients/' . $this->subClientId . '/summary');
        if ($summary['ok']) {
            $used  = (int) ($summary['data']['zones_used']  ?? $summary['data']['usage']['active_zones'] ?? 0);
            $limit = (int) ($summary['data']['zone_limit']  ?? $summary['data']['plan']['zones'] ?? 0);
            if ($limit > 0 && $used >= $limit) {
                return $this->withFlash(
                    'error',
                    "You've reached your zone limit ({$used}/{$limit}). "
                    . 'Please contact support to upgrade your plan.',
                    $this->renderZoneCreate()
                );
            }
        }

        $resp = $this->api->post('/api/v1/zones', [
            'name'          => $name,
            'sub_client_id' => $this->subClientId,
        ]);

        if (!$resp['ok']) {
            return $this->withFlash('error', $this->apiError($resp, 'Failed to create zone.'), $this->renderZoneCreate());
        }

        // SEC-M01: escape user-supplied name before embedding in flash message.
        $this->rotateCsrf(); // FIX-M5: rotate token after successful mutation.
        $this->flash('success', 'Zone ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ' created.');
        return $this->redirectTo('zones');
    }

    /**
     * EXPORT-01: stream the zone's BIND-format text as a file download.
     *
     * GET ?a=zone-export&zone={id}  — no POST body needed; uses GET param.
     *
     * The PanelDNS export endpoint returns text/plain, not JSON, so we check
     * HTTP status directly via 'status' in the API response rather than 'ok'
     * (which requires a JSON body with {"ok":true}). The raw body is in 'raw_body'.
     *
     * Outputs headers + body and exits. Never returns to the WHMCS template
     * engine — that's intentional for file downloads. On error (API failure,
     * ownership check, provider not configured), redirects with a flash message
     * so the client sees a readable error rather than a garbled download.
     */
    private function doZoneExport(): array
    {
        $zoneId = (int) ($_GET['zone'] ?? 0);
        if ($zoneId <= 0) return $this->withFlash('error', 'Zone ID required.', $this->renderZonesList());

        // SEC-OWN: verify this zone belongs to the current sub-client before exporting.
        $zone = $this->fetchOwnZone($zoneId);
        if (!$zone) return $this->withFlash('error', 'Zone not found.', $this->renderZonesList());

        $resp = $this->api->get("/api/v1/zones/{$zoneId}/export");

        // The export endpoint returns text/plain; 'ok' is always false for non-JSON.
        // Accept any 2xx status as success and use 'raw_body' directly.
        if (($resp['status'] ?? 0) < 200 || ($resp['status'] ?? 0) >= 300 || trim((string) $resp['raw_body']) === '') {
            return $this->withFlash(
                'error',
                'Export failed. The zone may not have a DNS provider configured yet.',
                $this->renderRecords()
            );
        }

        $zoneName = preg_replace('/[^a-z0-9._-]/i', '_', (string) ($zone['name'] ?? 'zone'));
        $filename = $zoneName . '.zone';

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($resp['raw_body']));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $resp['raw_body'];
        exit;
    }

    /**
     * DNSSEC-01: enable or disable DNSSEC signing on a zone.
     *
     * POST ?a=do-dnssec-toggle with zone_id + enable (1|0).
     * Calls POST /api/v1/zones/{zone}/dnssec {"enable": bool}.
     * On success, redirects back to the records page with a flash message
     * that includes the DS records if DNSSEC was just enabled.
     */
    private function doDnssecToggle(): array
    {
        $zoneId = (int) ($_POST['zone_id'] ?? 0);
        if (!$this->fetchOwnZone($zoneId)) {
            return $this->withFlash('error', 'Zone not found.', $this->renderZonesList());
        }

        $enable = isset($_POST['enable']) && (string) $_POST['enable'] === '1';

        $resp = $this->api->post("/api/v1/zones/{$zoneId}/dnssec", [
            'enable' => $enable,
        ]);

        if (!$resp['ok']) {
            $this->flash('error', 'DNSSEC ' . ($enable ? 'enable' : 'disable') . ' failed: '
                . $this->apiError($resp, 'Unknown error.'));
            return $this->redirectTo('records', "&zone={$zoneId}");
        }

        $this->rotateCsrf();
        if ($enable) {
            $this->flash('success', 'DNSSEC enabled. Add the DS records below to your domain registrar to complete setup.');
        } else {
            $this->flash('success', 'DNSSEC disabled.');
        }
        return $this->redirectTo('records', "&zone={$zoneId}");
    }

    /**
     * DNSSEC-01: fetch the current DNSSEC state for a zone.
     *
     * Returns null if the zone has no provider or the provider doesn't support
     * DNSSEC (any non-2xx response). Callers must guard on null before using.
     *
     * @return array{enabled: bool, algorithm: string|null, ds_records: string[], last_synced_at: string|null}|null
     */
    private function fetchDnssecStatus(int $zoneId): ?array
    {
        $resp = $this->api->get("/api/v1/zones/{$zoneId}/dnssec");
        if (!$resp['ok']) return null;
        $d = $resp['data'] ?? null;
        if (!is_array($d)) return null;
        return [
            'enabled'        => (bool) ($d['enabled'] ?? false),
            'algorithm'      => ($d['algorithm'] !== null && $d['algorithm'] !== '') ? (string) $d['algorithm'] : null,
            'ds_records'     => is_array($d['ds_records']) ? array_values($d['ds_records']) : [],
            'last_synced_at' => isset($d['last_synced_at']) ? (string) $d['last_synced_at'] : null,
        ];
    }

    private function doZoneImport(): array
    {
        $zoneId   = (int) ($_POST['zone_id'] ?? 0);
        $bindText = (string) ($_POST['bind'] ?? '');

        if ($zoneId <= 0)        return $this->withFlash('error', 'Pick a zone first.', $this->renderZoneImport());
        // SEC-H9: ownership check BEFORE the size cap — prevents a client from triggering
        // a 512 KB string allocation on every request simply by submitting a large payload
        // for a zone ID they do not own.
        if (!$this->fetchOwnZone($zoneId)) {
            return $this->withFlash('error', 'Zone not found.', $this->renderZoneImport());
        }
        if (trim($bindText) === '') return $this->withFlash('error', 'Paste BIND-format zone text.', $this->renderZoneImport());
        // SEC-M03: cap import payload to prevent memory-exhaustion DoS.
        if (strlen($bindText) > 512 * 1024) {
            return $this->withFlash('error', 'Import data too large (max 512 KB).', $this->renderZoneImport());
        }

        $resp = $this->api->post("/api/v1/zones/{$zoneId}/import", [
            'bind' => $bindText,
        ]);

        if (!$resp['ok']) {
            return $this->withFlash('error', 'Import failed: ' . $this->apiError($resp), $this->renderZoneImport());
        }

        $count = $resp['data']['imported'] ?? '?';
        $this->rotateCsrf(); // FIX-M5: rotate token after successful mutation.
        $this->flash('success', "Imported {$count} records into the zone.");
        return $this->redirectTo('records', "&zone={$zoneId}");
    }

    private function doZoneDelete(): array
    {
        $zoneId = (int) ($_POST['zone_id'] ?? 0);
        if ($zoneId <= 0 || !$this->fetchOwnZone($zoneId)) {
            return $this->withFlash('error', 'Zone not found.', $this->renderZonesList());
        }

        $resp = $this->api->delete("/api/v1/zones/{$zoneId}");
        if (!$resp['ok']) {
            return $this->withFlash('error', 'Delete failed: ' . $this->apiError($resp), $this->renderZonesList());
        }

        $this->rotateCsrf(); // FIX-M5: rotate token after successful mutation.
        $this->flash('success', 'Zone deleted.');
        return $this->redirectTo('zones');
    }

    private function doRecordCreate(): array
    {
        $zoneId = (int) ($_POST['zone_id'] ?? 0);
        if (!$this->fetchOwnZone($zoneId)) {
            return $this->withFlash('error', 'Zone not found.', $this->renderZonesList());
        }

        try {
            $payload = $this->recordPayloadFromPost();
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirectTo('records', "&zone={$zoneId}");
        }
        $resp = $this->api->post("/api/v1/zones/{$zoneId}/records", $payload);

        if (!$resp['ok']) {
            $this->flash('error', 'Add record failed: ' . $this->apiError($resp));
        } else {
            $this->rotateCsrf(); // FIX-M5: rotate token after successful mutation.
            $this->flash('success', 'Record added.');
        }
        return $this->redirectTo('records', "&zone={$zoneId}");
    }

    private function doRecordUpdate(): array
    {
        $zoneId   = (int) ($_POST['zone_id'] ?? 0);
        $recordId = (int) ($_POST['record_id'] ?? 0);
        if (!$this->fetchOwnZone($zoneId) || $recordId <= 0) {
            return $this->withFlash('error', 'Record not found.', $this->renderZonesList());
        }

        // FIX-H3: verify the record belongs to this zone before mutating it.
        $rec = $this->api->get("/api/v1/zones/{$zoneId}/records/{$recordId}");
        if (empty($rec['ok'])) {
            return $this->withFlash('error', 'Record not found.', $this->renderZonesList());
        }

        try {
            $payload = $this->recordPayloadFromPost();
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirectTo('records', "&zone={$zoneId}");
        }
        $resp = $this->api->patch("/api/v1/zones/{$zoneId}/records/{$recordId}", $payload);

        if (!$resp['ok']) {
            $this->flash('error', 'Update failed: ' . $this->apiError($resp));
        } else {
            $this->rotateCsrf(); // FIX-M5: rotate token after successful mutation.
            $this->flash('success', 'Record updated.');
        }
        return $this->redirectTo('records', "&zone={$zoneId}");
    }

    private function doRecordDelete(): array
    {
        $zoneId   = (int) ($_POST['zone_id'] ?? 0);
        $recordId = (int) ($_POST['record_id'] ?? 0);
        if (!$this->fetchOwnZone($zoneId) || $recordId <= 0) {
            return $this->withFlash('error', 'Record not found.', $this->renderZonesList());
        }

        // FIX-H3: verify the record belongs to this zone before deleting it.
        $rec = $this->api->get("/api/v1/zones/{$zoneId}/records/{$recordId}");
        if (empty($rec['ok'])) {
            return $this->withFlash('error', 'Record not found.', $this->renderZonesList());
        }

        $resp = $this->api->delete("/api/v1/zones/{$zoneId}/records/{$recordId}");
        if (!$resp['ok']) {
            $this->flash('error', 'Delete failed: ' . $this->apiError($resp));
        } else {
            $this->rotateCsrf(); // FIX-M5: rotate token after successful mutation.
            $this->flash('success', 'Record deleted.');
        }
        return $this->redirectTo('records', "&zone={$zoneId}");
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Verify a zone belongs to this customer before any mutation. */
    private function fetchOwnZone(int $zoneId): ?array
    {
        $resp = $this->api->get("/api/v1/zones/{$zoneId}");
        if (!$resp['ok']) return null;
        $z = $resp['data'] ?? null;
        if (!$z) return null;
        if ((int) ($z['sub_client_id'] ?? 0) !== $this->subClientId) return null;
        return $z;
    }

    /**
     * NS-CARD-01: fetch the org's nameservers for display on DNS pages.
     * Cached for 5 minutes via WHMCS\Cache\Store so browsing between records
     * pages does not produce a new API call per page load.
     *
     * @return string[] Array of NS hostnames, e.g. ['ns1.example.com', 'ns2.example.com']
     */
    private function fetchNameservers(): array
    {
        $cacheKey = 'paneldns_ns_' . $this->subClientId;
        try {
            if (class_exists('\\WHMCS\\Cache\\Store')) {
                $cached = (new \WHMCS\Cache\Store)->get($cacheKey);
                if (is_array($cached)) return $cached;
            }
        } catch (\Throwable $e) { /* fall through to live call */ }

        $resp = $this->api->nameservers();
        $ns   = ($resp['ok'] && !empty($resp['data']['nameservers']))
            ? (array) $resp['data']['nameservers']
            : [];

        try {
            if (class_exists('\\WHMCS\\Cache\\Store')) {
                (new \WHMCS\Cache\Store)->put($cacheKey, $ns, 300); // 5 minutes
            }
        } catch (\Throwable $e) { /* swallow */ }

        return $ns;
    }

    /** Cached list of zones belonging to this customer. */
    private function fetchOwnZones(): array
    {
        $resp = $this->api->get("/api/v1/zones?sub_client_id={$this->subClientId}&per_page=100");
        return $resp['ok'] ? ($resp['data'] ?? []) : [];
    }

    private function recordPayloadFromPost(): array
    {
        // SEC-M02: allowlist record types before forwarding to the API.
        static $allowed = [
            'A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA',
            'PTR', 'TLSA', 'SSHFP', 'HTTPS', 'NAPTR',
        ];
        $type = strtoupper(trim((string) ($_POST['type'] ?? 'A')));
        if (!in_array($type, $allowed, true)) {
            throw new \InvalidArgumentException(
                'Invalid record type: ' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8')
            );
        }
        $name    = trim((string) ($_POST['name'] ?? '@'));
        $content = trim((string) ($_POST['content'] ?? ''));

        // FIX-M3/M4: validate name and content lengths and reject control characters.
        if (strlen($name) > 253 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new \InvalidArgumentException('Record name is invalid or too long.');
        }
        if (strlen($content) > 4096 || preg_match('/[\x00\r\n]/', $content)) {
            throw new \InvalidArgumentException('Record content is invalid or too long.');
        }

        return array_filter([
            'name'     => $name,
            'type'     => $type,
            'content'  => $content,
            // FIX-M4: enforce minimum TTL of 60 seconds server-side.
            'ttl'      => max(60, (int) ($_POST['ttl'] ?? 3600)),
            'priority' => isset($_POST['priority']) && $_POST['priority'] !== ''
                ? (int) $_POST['priority']
                : null,
        ], fn ($v) => $v !== null);
    }

    // ── Flash + redirect (full page POST/redirect pattern) ───────────────────

    private function flash(string $type, string $msg): void
    {
        // SEC-L03: avoid @session_start() — masks misconfig and hides session errors.
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        // FIX-M1: cap flash message length — prevents excessively long API errors
        // from leaking to the client area.
        $_SESSION['paneldns_flash'] = ['type' => $type, 'msg' => substr((string) $msg, 0, 512)];
    }

    /** FIX-M17: extract and cap API error strings before use in flash messages. */
    private function apiError(array $resp, string $fallback = 'unknown'): string
    {
        return substr((string) ($resp['error'] ?? $fallback), 0, 256);
    }

    private function popFlash(): ?array
    {
        // SEC-L03: avoid @session_start() — masks misconfig and hides session errors.
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $f = $_SESSION['paneldns_flash'] ?? null;
        unset($_SESSION['paneldns_flash']);
        return $f;
    }

    private function withFlash(string $type, string $msg, array $renderResult): array
    {
        $this->flash($type, $msg);
        $renderResult['vars']['flash'] = ['type' => $type, 'msg' => $msg];
        return $renderResult;
    }

    private function redirectTo(string $action, string $extra = ''): array
    {
        $url = 'clientarea.php?action=productdetails'
             . '&id=' . (int) $this->params['serviceid']
             . '&modop=custom'
             . '&a=' . urlencode($action)
             . $extra;

        // Re-render the destination — WHMCS doesn't support module
        // returning a redirect array, so we re-render server-side. The
        // flash carries the success/failure message.
        return match ($action) {
            'zones'   => $this->renderZonesList(),
            'records' => $this->renderRecords(),
            default   => $this->renderZonesList(),
        };
    }

    // ── CSRF ─────────────────────────────────────────────────────────────────

    /**
     * Generate a per-session CSRF token tied to the customer's WHMCS session
     * and the specific service ID. Bound to service so a leaked token
     * from one product can't be used to mutate another's DNS.
     */
    private function csrfToken(): string
    {
        // SEC-L03: avoid @session_start() — masks misconfig and hides session errors.
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $key = 'paneldns_csrf_' . (int) $this->params['serviceid'];
        if (empty($_SESSION[$key])) {
            $_SESSION[$key] = bin2hex(random_bytes(24));
        }
        return $_SESSION[$key];
    }

    /**
     * FIX-M5: rotate the CSRF token after each successful mutation to prevent
     * replay attacks on the same token. The new token is stored in session so the
     * next page render picks it up via csrfToken().
     */
    private function rotateCsrf(): void
    {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $key = 'paneldns_csrf_' . (int) $this->params['serviceid'];
        $_SESSION[$key] = bin2hex(random_bytes(24));
    }

    private function requireCsrf(): void
    {
        // SEC-L03: avoid @session_start() — masks misconfig and hides session errors.
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $expected = $_SESSION['paneldns_csrf_' . (int) $this->params['serviceid']] ?? '';
        $supplied = (string) ($_POST['csrf'] ?? '');
        if ($expected === '' || !hash_equals($expected, $supplied)) {
            http_response_code(403);
            die('CSRF token mismatch. Please return to the previous page and try again.');
        }
    }
}
