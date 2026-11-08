<?php

/**
 * EmbeddedDnsManager — T1.4 in-WHMCS DNS management for paneldnsreseller.
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
 * Pages are selected by the `customAction` request parameter (CLIENT-ROUTE-01);
 * every link and form in templates/client/*.tpl carries it.
 *
 * @package paneldns-whmcs
 */

if (!defined('WHMCS')) {
    die('Access denied.');
}

class PanelDnsEmbeddedDnsManager
{
    /**
     * IMPORT-01: PanelDNS validates zone_file as max:51200 (characters). Capped here in
     * bytes, which is never more permissive.
     */
    const IMPORT_MAX_BYTES = 51200;

    /** PAGING-01: zones page at a fixed 50 → 20 pages = 1,000 zones per sub-client. */
    const ZONES_MAX_PAGES = 20;

    /** Records honour per_page (1–500): 20 pages of 500 = 10,000 records. */
    const RECORDS_PER_PAGE  = 500;
    const RECORDS_MAX_PAGES = 20;

    /** RATE-CALLS-01: DNSSEC status is a round trip to the DNS provider — cache it. */
    const TTL_DNSSEC = 300;

    /** @var PanelDnsResellerApi */ private $api;
    /** @var int */         private $subClientId;
    /** @var array */       private $params;
    /** @var array<int,?array> per-request zone ownership lookups */ private $zoneCache = [];
    /** @var ?array per-request zone list */ private $zonesCache = null;

    public function __construct(PanelDnsResellerApi $api, int $subClientId, array $params)
    {
        $this->api = $api;
        $this->subClientId = $subClientId;
        $this->params = $params;
    }

    // ── Dispatch ─────────────────────────────────────────────────────────────

    /**
     * Returns ['templatefile'=>..., 'vars'=>...] for WHMCS to render.
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
                    // These are page requests: answer with a page, not a bare JSON body.
                    return [
                        'templatefile' => 'templates/client/overview',
                        'vars' => [
                            'paneldns_error'       => 'Too many requests. Please wait a minute and try again',
                            'paneldns_nameservers' => [],
                        ],
                    ];
                }
                $rlStore->put($rlKey, $hits + 1, 60);
            } catch (\Throwable $e) {
                if (function_exists('logModuleCall')) {
                    logModuleCall('paneldnsreseller', 'rl_cache_fail', [], get_class($e), '');
                }
            }
        }

        $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

        // Verify CSRF on mutating requests.
        if ($isPost) {
            $this->requireCsrf();
        }

        // Mutations only on POST — a GET to a do-* URL just shows the zone list.
        if (str_starts_with($action, 'do-') && !$isPost) {
            return $this->renderZonesList();
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
        // PAGING-01: PanelDNS returns 50 zones per page and ignores per_page, so the old
        // single per_page=100 request showed at most 50. Follow meta.total instead.
        $resp = $this->ownZones();
        return [
            'templatefile' => 'templates/client/zones-list',
            'vars' => [
                'zones'           => $resp['data'],
                'zones_total'     => (int) ($resp['total'] ?? count($resp['data'])),
                'zones_truncated' => !empty($resp['truncated']),
                'error'           => $resp['ok'] ? null : ($resp['error'] ?? 'Failed to load zones'),
                'service_id'      => (int) $this->params['serviceid'],
                'flash'           => $this->popFlash(),
                'csrf'            => $this->csrfToken(),
            ],
        ];
    }

    /**
     * @param int|null $zoneId the zone to show; null = the ?zone= query parameter.
     *   REDIRECT-01 (v1.9.3): after a record POST the zone id is only in the POST body,
     *   and the old $_GET-only lookup sent the client back to the zone list.
     */
    private function renderRecords(?int $zoneId = null): array
    {
        $zoneId = $zoneId ?? (int) ($_GET['zone'] ?? 0);
        if ($zoneId <= 0) return $this->renderZonesList();

        $zone    = $this->fetchOwnZone($zoneId);
        if (!$zone) return $this->withFlash('error', 'Zone not found.', $this->renderZonesList());

        // PAGING-01: records honour per_page (max 500); follow meta past the first page.
        $records = $this->api->getAllPages(
            "/api/v1/zones/{$zoneId}/records",
            ['per_page' => self::RECORDS_PER_PAGE],
            self::RECORDS_MAX_PAGES
        );

        // DNSSEC-01: fetch DNSSEC status for display. Non-fatal — if the zone has
        // no provider or the provider doesn't support DNSSEC, we pass supported=false
        // and the template hides the DNSSEC card silently.
        $dnssec = $this->fetchDnssecStatus($zoneId);

        return [
            'templatefile' => 'templates/client/zone-records',
            'vars' => [
                'zone'           => $zone,
                'records'        => $records['data'],
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
                'service_id'     => (int) $this->params['serviceid'],
                'csrf'           => $this->csrfToken(),
                'zones'          => $this->fetchOwnZones(),
                'import_max_kb'  => (int) (self::IMPORT_MAX_BYTES / 1024),
                'import_max'     => self::IMPORT_MAX_BYTES,
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

        // QUOTA-02 (v1.9.3): no pre-flight summary call. PanelDNS enforces the zone limit
        // itself and answers 422 with a readable message, shown below. The old pre-flight
        // read fields the summary does not return (zones_used, zone_limit), so it never
        // fired, at the cost of one API call per create.
        $resp = $this->api->post('/api/v1/zones', [
            'name'          => $name,
            'sub_client_id' => $this->subClientId,
        ]);

        if (!$resp['ok']) {
            return $this->withFlash('error', $this->apiError($resp, 'Failed to create zone.'), $this->renderZoneCreate());
        }

        $this->zonesCache = null;
        // SEC-M01: escape user-supplied name before embedding in flash message.
        $this->rotateCsrf(); // FIX-M5: rotate token after successful mutation.
        $this->flash('success', 'Zone ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ' created.');
        return $this->redirectTo('zones');
    }

    /**
     * EXPORT-01: stream the zone's BIND-format text as a file download.
     *
     * GET ?customAction=zone-export&zone={id}  — no POST body needed; uses GET param.
     *
     * The PanelDNS export endpoint returns text/plain, not JSON, so we check
     * HTTP status directly via 'status' in the API response rather than 'ok'
     * (which requires a JSON body with {"ok":true}). The raw body is in 'raw_body'.
     *
     * Outputs headers + body and exits. Never returns to the WHMCS template
     * engine — that's intentional for file downloads. On error (API failure,
     * ownership check, provider not configured), re-renders with a flash message
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
        $status = (int) ($resp['status'] ?? 0);
        if ($status < 200 || $status >= 300 || trim((string) ($resp['raw_body'] ?? '')) === '') {
            $why = $status >= 400 ? $this->apiError($resp, '') : '';
            return $this->withFlash(
                'error',
                $why !== '' ? 'Export failed: ' . $why : 'Export failed. The zone may not have a DNS provider configured yet.',
                $this->renderRecords($zoneId)
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
     * POST ?customAction=do-dnssec-toggle with zone_id + enable (1|0).
     * Calls POST /api/v1/zones/{zone}/dnssec {"enable": bool}.
     * On success, re-renders the records page, which shows the DS records
     * if DNSSEC was just enabled.
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

        $this->forgetDnssecStatus($zoneId);

        if (!$resp['ok']) {
            $this->flash('error', 'DNSSEC ' . ($enable ? 'enable' : 'disable') . ' failed: '
                . $this->apiError($resp, 'Unknown error.'));
            return $this->redirectTo('records', $zoneId);
        }

        $this->rotateCsrf();
        if ($enable) {
            $this->flash('success', 'DNSSEC enabled. Add the DS records below to your domain registrar to complete setup.');
        } else {
            $this->flash('success', 'DNSSEC disabled.');
        }
        return $this->redirectTo('records', $zoneId);
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
        // RATE-CALLS-01: cache the answer (supported or not) for 5 minutes; dropped on
        // toggle and import.
        $key = $this->dnssecCacheKey($zoneId);
        try {
            if (class_exists('\WHMCS\Cache\Store')) {
                $hit = (new \WHMCS\Cache\Store)->get($key);
                if (is_array($hit) && array_key_exists('v', $hit)) return $hit['v'];
            }
        } catch (\Throwable $e) { /* live call */ }

        $resp  = $this->api->get("/api/v1/zones/{$zoneId}/dnssec");
        $state = null;
        $d = $resp['ok'] ? ($resp['data'] ?? null) : null;
        if (is_array($d)) {
            $state = [
                'enabled'        => (bool) ($d['enabled'] ?? false),
                'algorithm'      => (isset($d['algorithm']) && $d['algorithm'] !== '') ? (string) $d['algorithm'] : null,
                'ds_records'     => is_array($d['ds_records'] ?? null) ? array_values($d['ds_records']) : [],
                'last_synced_at' => isset($d['last_synced_at']) ? (string) $d['last_synced_at'] : null,
            ];
        }

        // Only real answers are cached — not a rate limit or a network failure.
        if ($resp['ok'] || in_array((int) $resp['status'], [404, 422], true)) {
            try {
                if (class_exists('\WHMCS\Cache\Store')) {
                    (new \WHMCS\Cache\Store)->put($key, ['v' => $state], self::TTL_DNSSEC);
                }
            } catch (\Throwable $e) { /* swallow */ }
        }
        return $state;
    }

    private function dnssecCacheKey(int $zoneId): string
    {
        return 'paneldns-dnssec:' . $this->api->identityHash() . ':' . $zoneId;
    }

    private function forgetDnssecStatus(int $zoneId): void
    {
        try {
            if (class_exists('\WHMCS\Cache\Store')) {
                (new \WHMCS\Cache\Store)->put($this->dnssecCacheKey($zoneId), null, 1);
            }
        } catch (\Throwable $e) { /* swallow */ }
    }

    /**
     * IMPORT-01 (v1.9.3): POST /api/v1/zones/{id}/import takes `zone_file` (max 51,200)
     * and answers {"ok": true, "data": null}. Up to 1.9.2 the module posted `bind`, so
     * every import failed validation with a 422, and it read a `data.imported` count the
     * endpoint never returns.
     */
    private function doZoneImport(): array
    {
        $zoneId   = (int) ($_POST['zone_id'] ?? 0);
        // `bind` was the form field's name up to 1.9.2; accept it from a cached page.
        $zoneText = (string) ($_POST['zone_file'] ?? $_POST['bind'] ?? '');

        if ($zoneId <= 0) return $this->withFlash('error', 'Pick a zone first.', $this->renderZoneImport());
        // SEC-H9: ownership check BEFORE the size cap — a client must not be able to make
        // the module do work for a zone ID they do not own.
        if (!$this->fetchOwnZone($zoneId)) {
            return $this->withFlash('error', 'Zone not found.', $this->renderZoneImport());
        }

        try {
            $payload = self::buildImportPayload($zoneText);
        } catch (\InvalidArgumentException $e) {
            return $this->withFlash('error', $e->getMessage(), $this->renderZoneImport());
        }

        $resp = $this->api->post("/api/v1/zones/{$zoneId}/import", $payload);

        if (!$resp['ok']) {
            return $this->withFlash('error', 'Import failed: ' . $this->apiError($resp), $this->renderZoneImport());
        }

        $this->forgetDnssecStatus($zoneId);
        $this->rotateCsrf(); // FIX-M5: rotate token after successful mutation.
        $this->flash('success', 'Zone file imported. The records below are what PanelDNS now holds for this zone.');
        return $this->redirectTo('records', $zoneId);
    }

    /**
     * The import request body — checks the text is present and within 50 KB.
     *
     * @throws \InvalidArgumentException with a client-readable message
     * @internal public for unit tests.
     */
    public static function buildImportPayload(string $zoneText): array
    {
        if (trim($zoneText) === '') {
            throw new \InvalidArgumentException('Paste the BIND-format zone file text.');
        }
        $bytes = strlen($zoneText);
        if ($bytes > self::IMPORT_MAX_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'Zone file too large (%s KB). PanelDNS accepts up to 50 KB per import — split the file and import it in parts.',
                number_format($bytes / 1024, 1)
            ));
        }
        return ['zone_file' => $zoneText];
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

        $this->zonesCache = null;
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
            return $this->redirectTo('records', $zoneId);
        }
        $resp = $this->api->post("/api/v1/zones/{$zoneId}/records", $payload);

        if (!$resp['ok']) {
            $this->flash('error', 'Add record failed: ' . $this->apiError($resp));
        } else {
            $this->rotateCsrf(); // FIX-M5: rotate token after successful mutation.
            $this->flash('success', 'Record added.');
        }
        return $this->redirectTo('records', $zoneId);
    }

    private function doRecordUpdate(): array
    {
        $zoneId   = (int) ($_POST['zone_id'] ?? 0);
        $recordId = (int) ($_POST['record_id'] ?? 0);
        if (!$this->fetchOwnZone($zoneId) || $recordId <= 0) {
            return $this->withFlash('error', 'Record not found.', $this->renderZonesList());
        }

        // FIX-H3 / RATE-CALLS-01: the record-belongs-to-zone check is PanelDNS's own:
        // PATCH /zones/{zone}/records/{record} finds the record WHERE zone_id = {zone}
        // and answers 404 otherwise, and the zone was just verified as this client's.
        // The separate GET of the record that used to precede it was a redundant call.
        try {
            $payload = $this->recordPayloadFromPost();
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirectTo('records', $zoneId);
        }
        $resp = $this->api->patch("/api/v1/zones/{$zoneId}/records/{$recordId}", $payload);

        if (!$resp['ok']) {
            $this->flash('error', 'Update failed: ' . $this->apiError($resp));
        } else {
            $this->rotateCsrf(); // FIX-M5: rotate token after successful mutation.
            $this->flash('success', 'Record updated.');
        }
        return $this->redirectTo('records', $zoneId);
    }

    private function doRecordDelete(): array
    {
        $zoneId   = (int) ($_POST['zone_id'] ?? 0);
        $recordId = (int) ($_POST['record_id'] ?? 0);
        if (!$this->fetchOwnZone($zoneId) || $recordId <= 0) {
            return $this->withFlash('error', 'Record not found.', $this->renderZonesList());
        }

        // FIX-H3: DELETE /zones/{zone}/records/{record} is scoped to the zone server-side
        // (404 for a record of another zone) — see doRecordUpdate().
        $resp = $this->api->delete("/api/v1/zones/{$zoneId}/records/{$recordId}");
        if (!$resp['ok']) {
            $this->flash('error', 'Delete failed: ' . $this->apiError($resp));
        } else {
            $this->rotateCsrf(); // FIX-M5: rotate token after successful mutation.
            $this->flash('success', 'Record deleted.');
        }
        return $this->redirectTo('records', $zoneId);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Verify a zone belongs to this customer before any mutation. Memoised per request:
     * a mutation followed by its re-render used to look the same zone up twice.
     */
    private function fetchOwnZone(int $zoneId): ?array
    {
        if ($zoneId <= 0) return null;
        if (array_key_exists($zoneId, $this->zoneCache)) return $this->zoneCache[$zoneId];

        $resp = $this->api->get("/api/v1/zones/{$zoneId}");
        $z = $resp['ok'] ? ($resp['data'] ?? null) : null;
        if (!is_array($z) || (int) ($z['sub_client_id'] ?? 0) !== $this->subClientId) {
            $z = null;
        }
        return $this->zoneCache[$zoneId] = $z;
    }

    /**
     * NS-CARD-01: the nameservers for the "point your domain here" card. Same source as
     * the overview and the welcome email — the product's NS overrides first, then the
     * org's nameservers (cached 5 minutes). Up to 1.9.2 this page ignored the overrides.
     *
     * @return string[]
     */
    private function fetchNameservers(): array
    {
        return PanelDnsResellerService::nameserversFor($this->api, $this->params);
    }

    /**
     * This customer's zones, every page (PAGING-01), memoised per request.
     *
     * @return array{ok:bool, data:array, error:?string, total:?int, truncated:bool}
     */
    private function ownZones(): array
    {
        if ($this->zonesCache === null) {
            $this->zonesCache = $this->api->getAllPages(
                '/api/v1/zones',
                ['sub_client_id' => $this->subClientId],
                self::ZONES_MAX_PAGES
            );
        }
        return $this->zonesCache;
    }

    /** Zones belonging to this customer (for the import picker). */
    private function fetchOwnZones(): array
    {
        return $this->ownZones()['data'];
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
            // FIX-M4: enforce minimum TTL of 60 seconds server-side (PanelDNS: 60–86400).
            'ttl'      => min(86400, max(60, (int) ($_POST['ttl'] ?? 3600))),
            'priority' => isset($_POST['priority']) && $_POST['priority'] !== ''
                ? (int) $_POST['priority']
                : null,
        ], fn ($v) => $v !== null);
    }

    // ── Flash + redirect (full page POST/redirect pattern) ───────────────────

    /**
     * SEC-L03: start the session if WHMCS has not (it normally has), without @ — but
     * only while it can still be started: once output has gone out session_start()
     * cannot succeed and would only raise a warning.
     */
    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
    }

    private function flash(string $type, string $msg): void
    {
        // SEC-L03: avoid @session_start() — masks misconfig and hides session errors.
        $this->ensureSession();
        // FIX-M1: cap flash message length — prevents excessively long API errors
        // from leaking to the client area.
        $_SESSION['paneldns_flash'] = ['type' => $type, 'msg' => substr((string) $msg, 0, 512)];
    }

    /** FIX-M17: extract and cap API error strings before use in flash messages. */
    private function apiError(array $resp, string $fallback = 'unknown'): string
    {
        $e = (string) ($resp['error'] ?? '');
        return substr($e !== '' ? $e : $fallback, 0, 256);
    }

    private function popFlash(): ?array
    {
        // SEC-L03: avoid @session_start() — masks misconfig and hides session errors.
        $this->ensureSession();
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

    private function redirectTo(string $action, ?int $zoneId = null): array
    {
        // Re-render the destination — WHMCS doesn't support module
        // returning a redirect array, so we re-render server-side. The
        // flash carries the success/failure message.
        return match ($action) {
            'zones'   => $this->renderZonesList(),
            'records' => $this->renderRecords($zoneId),
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
        $this->ensureSession();
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
        $this->ensureSession();
        $key = 'paneldns_csrf_' . (int) $this->params['serviceid'];
        $_SESSION[$key] = bin2hex(random_bytes(24));
    }

    private function requireCsrf(): void
    {
        // SEC-L03: avoid @session_start() — masks misconfig and hides session errors.
        $this->ensureSession();
        $expected = $_SESSION['paneldns_csrf_' . (int) $this->params['serviceid']] ?? '';
        $supplied = (string) ($_POST['csrf'] ?? '');
        if ($expected === '' || !hash_equals($expected, $supplied)) {
            http_response_code(403);
            die('CSRF token mismatch. Please return to the previous page and try again.');
        }
    }
}
