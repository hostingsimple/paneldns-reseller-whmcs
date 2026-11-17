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

require_once __DIR__ . '/PanelDnsResellerHooks.php'; // ZONE-OWN-01: domain ownership + isYes()

class PanelDnsEmbeddedDnsManager
{
    /**
     * ZONE-OWN-03: the only text a client sees when a name is refused because it is, or
     * overlaps, someone else's zone. PanelDNS's own wording ("Zone already exists.")
     * told a customer which names other customers host; the detail goes to the admin
     * Activity Log instead.
     */
    const MSG_UNAVAILABLE = 'This zone name is unavailable.';

    /** ZONE-OWN-01: a name that is not one of the client's own WHMCS domains. */
    const MSG_NOT_OWNED = 'You can only add zones for domains on your account.';

    /** ZONE-OWN-02: an ownership or overlap lookup failed — refuse rather than guess. */
    const MSG_CHECK_FAILED = 'Could not check this zone name right now. Please try again in a few minutes.';

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
        // PanelDNS lower-cases zone names; do it first so the ownership checks compare
        // the name PanelDNS will store.
        $name = strtolower(trim((string) ($_POST['name'] ?? '')));
        if ($name === '') return $this->withFlash('error', 'Zone name is required.', $this->renderZoneCreate());

        // FIX-H4: validate zone name — 253 char limit, no consecutive dots, RFC-safe chars only.
        if (
            strlen($name) > 253
            || str_contains($name, '..')
            || !preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9_\-]|\.[a-zA-Z0-9])*$/', $name)
        ) {
            return $this->withFlash('error', 'Invalid zone name.', $this->renderZoneCreate());
        }

        // ZONE-OWN-01 (v1.9.5): only the client's own domains, unless the product allows
        // others — and then never a name inside, above or equal to another customer's zone.
        $refusal = $this->zoneCreateRefusal($name);
        if ($refusal !== null) {
            return $this->withFlash('error', $refusal, $this->renderZoneCreate());
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
            return $this->withFlash('error', $this->zoneCreateError($resp, $name), $this->renderZoneCreate());
        }

        $this->zonesCache = null;
        // SEC-M01: escape user-supplied name before embedding in flash message.
        $this->rotateCsrf(); // FIX-M5: rotate token after successful mutation.
        $this->flash('success', 'Zone ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ' created.');
        return $this->redirectTo('zones');
    }

    /**
     * ZONE-OWN-01/02 (v1.9.5): why this client may not create a zone for $name, or null.
     *
     * Up to 1.9.4 any name that passed the syntax check was sent to PanelDNS, which only
     * refused an exact duplicate in the org. A customer could add `shop.victim.com`, or
     * `victim.com` before its owner did; both sit on the reseller's shared nameservers,
     * which answer from the most specific zone they hold, so the customer took over
     * another customer's names (mail, TLS certificates, phishing).
     *
     * Allowed without further checks: a domain that is Active on this client's own
     * WHMCS account (tbldomains, exact name). A domain whose transfer is still pending
     * is not the client's yet. The service's own Domain field is deliberately NOT
     * proof (ZONE-OWN-05): the customer types it on the order form ("use my existing
     * domain") and WHMCS never verifies it, the same trap as email in v1.9.4.
     *
     * With "Allow Zones for Domains Not in This Account" (configoption12) ticked, any
     * other name is allowed unless it equals, contains or sits inside a zone that is
     * not this sub-client's (another sub-client's, or the reseller's own).
     */
    private function zoneCreateRefusal(string $name): ?string
    {
        $userId = (int) ($this->params['userid'] ?? $this->params['clientsdetails']['userid'] ?? 0);

        try {
            $statuses = PanelDnsResellerHooks::clientDomainStatuses($userId, $name);
        } catch (\Throwable $e) {
            $this->logZoneRefusal($name, 'tbldomains lookup failed (' . get_class($e) . ')');
            return self::MSG_CHECK_FAILED;
        }

        if (in_array('Active', $statuses, true)) {
            return null;
        }

        // configoption12 — "Allow Zones for Domains Not in This Account" (default off).
        if (!PanelDnsResellerHooks::isYes($this->params['configoption12'] ?? '')) {
            if (in_array('Pending Transfer', $statuses, true)) {
                $this->logZoneRefusal($name, 'domain transfer not completed yet');
                return 'The transfer of ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                    . ' has not completed yet. You can add its zone once it has.';
            }
            $this->logZoneRefusal($name, 'not an Active domain on the client\'s account');
            return self::MSG_NOT_OWNED;
        }

        return $this->overlapRefusal($name);
    }

    /**
     * ZONE-OWN-02: refuse $name when a zone that is not this sub-client's is the same
     * name, a parent of it or a child of it. Fails closed: a lookup that errors or is
     * cut short refuses the name.
     *
     * At most three listing calls, however deep the name:
     *   - the 1- and 2-label suffixes (`com`, `example.com`) by exact `?name=`;
     *   - one `?search=` (substring) on the last three labels — or the whole name when it
     *     is shorter — which returns every longer parent, the name itself and every
     *     child, since all of them contain that suffix. Matches are re-checked here, so
     *     an unrelated hit such as `myexample.com` is ignored.
     * Every listing is paged (PanelDNS returns 50 zones per page, ignoring per_page).
     */
    private function overlapRefusal(string $name): ?string
    {
        $labels = explode('.', $name);
        $count  = count($labels);

        $lookups = [];
        for ($n = 1; $n <= min(2, $count - 1); $n++) {
            $parent = implode('.', array_slice($labels, -$n));
            $lookups[] = [['name' => $parent], fn (string $z) => $z === $parent];
        }
        $suffix = implode('.', array_slice($labels, -min(3, $count)));
        $lookups[] = [['search' => $suffix], fn (string $z) => self::namesOverlap($z, $name)];

        foreach ($lookups as [$query, $matches]) {
            $resp = $this->api->getAllPages('/api/v1/zones', $query, self::ZONES_MAX_PAGES);
            if (!$resp['ok'] || !empty($resp['truncated'])) {
                $this->logZoneRefusal($name, 'overlap check failed (' . http_build_query($query) . ', HTTP '
                    . (int) ($resp['status'] ?? 0) . (!empty($resp['truncated']) ? ', truncated' : '') . ')');
                return self::MSG_CHECK_FAILED;
            }
            foreach ($resp['data'] as $zone) {
                if (!is_array($zone)) continue;
                $zoneName = strtolower(rtrim(trim((string) ($zone['name'] ?? '')), '.'));
                if ($zoneName === '' || !$matches($zoneName)) continue;
                if ((int) ($zone['sub_client_id'] ?? 0) === $this->subClientId) continue; // this client's own
                $this->logZoneRefusal($name, 'overlaps zone ' . $zoneName . ' (#' . (int) ($zone['id'] ?? 0)
                    . ', sub-client ' . ((int) ($zone['sub_client_id'] ?? 0) ?: 'none (org zone)') . ')');
                return self::MSG_UNAVAILABLE;
            }
        }
        return null;
    }

    /**
     * True when two zone names are the same, or one is a parent of the other.
     *
     * @internal public for unit tests.
     */
    public static function namesOverlap(string $a, string $b): bool
    {
        $a = strtolower(rtrim($a, '.'));
        $b = strtolower(rtrim($b, '.'));
        return $a !== '' && $b !== ''
            && ($a === $b || str_ends_with($a, '.' . $b) || str_ends_with($b, '.' . $a));
    }

    /**
     * ZONE-OWN-03: the client-facing line for a failed create. A 409, or a 422 about the
     * name being taken, shows MSG_UNAVAILABLE; the PanelDNS detail goes to the Activity
     * Log. Everything else (zone limit reached, plan restrictions) is shown as before.
     */
    private function zoneCreateError(array $resp, string $name): string
    {
        $status = (int) ($resp['status'] ?? 0);
        if ($status === 409 || ($status === 422 && self::isNameConflict($resp))) {
            $this->logZoneRefusal($name, 'PanelDNS HTTP ' . $status . ': ' . $this->apiError($resp, '(no message)'));
            return self::MSG_UNAVAILABLE;
        }
        return $this->apiError($resp, 'Failed to create zone.');
    }

    /**
     * A 422 that refuses the name because it is taken or overlaps another zone: a
     * validation error on `name`, or wording such as "already exists" or "unavailable"
     * (PanelDNS's own race-path duplicate, its overlap refusal, or a DNS provider's).
     *
     * @internal public for unit tests.
     */
    public static function isNameConflict(array $resp): bool
    {
        if (is_array($resp['errors'] ?? null) && array_key_exists('name', $resp['errors'])) {
            return true;
        }
        return (bool) preg_match(
            '/already\s+exist|unavailable|not\s+available|overlap|conflict|in\s+use|already\s+been\s+taken|duplicate/i',
            (string) ($resp['error'] ?? '')
        );
    }

    /** ZONE-OWN-03: a refused zone name, with the reason, for the admin Activity Log. */
    private function logZoneRefusal(string $name, string $why): void
    {
        if (!function_exists('logActivity')) return;
        // $name passed the zone-name regex (letters, digits, '-', '_', '.') before this.
        logActivity(
            'PanelDNS: refused zone ' . $name . ' for service #' . (int) ($this->params['serviceid'] ?? 0)
                . ' (sub-client ' . $this->subClientId . '): ' . substr($why, 0, 300),
            (int) ($this->params['userid'] ?? 0)
        );
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
