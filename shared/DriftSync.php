<?php

/**
 * PanelDnsResellerDriftSync — daily reconciliation between WHMCS service state and the
 * upstream PanelDNS state.
 *
 * DRIFT-SOT-01 (v1.9.6) — the same policy as paneldns-whmcs 1.1.4's DRIFT-TRUTH-01:
 * WHMCS is the source of truth for a service's status. Drift sync only ever moves a WHMCS
 * service to a MORE restrictive status (STATUS_RANK), and never un-suspends or activates
 * anything WHMCS has suspended, left pending or terminated:
 *
 *   1. WHMCS Active, PanelDNS suspended or cancelled (changed directly in PanelDNS):
 *      stamp the WHMCS service Suspended / Terminated.
 *   2. WHMCS Suspended, PanelDNS cancelled: stamp WHMCS Terminated.
 *   3. WHMCS Suspended, PanelDNS active: suspend the sub-client in PanelDNS again. WHMCS
 *      is not touched. Up to 1.9.5 this case set the WHMCS service Active (the docblock
 *      promised the re-suspend; the code did the opposite), so a suspension for
 *      non-payment or abuse was lifted the night after anyone re-activated the
 *      sub-client in PanelDNS.
 *   4. Anything else that differs (a Pending, Completed or other WHMCS status against
 *      any upstream state): written to the Activity Log as "drift reported, not
 *      applied", never applied. Up to 1.9.5 an unpaid order with a sub-client id became
 *      Active.
 *
 * DRIFT-404-01 (v1.9.6) — deliberately NOT paneldns-whmcs's rule (there, 404 → Terminated):
 * the 2026-11-18 rescan (section 08) requires that a 404 never terminate here. It is
 * logged and the service is flagged for review with a [paneldns-drift:not-found] marker
 * in its admin notes. A server whose lookups are mostly 404s, or that answers 401/403, is
 * treated as a token or organisation mistake (for example another org's API token in the
 * server's Access Hash): its services are left alone and one Activity Log line names the
 * server. Up to 1.9.5 every 404 set the service Terminated, so a wrong token
 * mass-terminated services in WHMCS.
 *
 * ID-STRICT-01 (v1.9.6, ported from paneldns-whmcs 1.1.4): the sub-client id is read with
 * parseId(); a value in Dedicated IP that is not a plain positive whole number (an IP
 * address, say) is skipped, never cast.
 *
 * DRIFT-SCOPE-01 (v1.9.3): this module's (paneldnsreseller) services only, matching
 * the operator module (paneldnsplatform), whose own drift sync now reconciles only its
 * services. Up to 1.9.2 both copies reconciled both modules' services.
 *
 * Called from hooks.php on the DailyCronJob hook. Throttled to 100
 * services per run; the next cron picks up where we left off via
 * last_synced_at ordering. A full WHMCS install with thousands of
 * paneldns services therefore reconciles fully over a few days.
 */

if (!defined('WHMCS')) {
    die('Access denied.');
}

if (!class_exists('PanelDnsResellerDriftSync', false)) {
class PanelDnsResellerDriftSync
{
    const MAX_PER_RUN = 100;

    /** WHMCS module (folder) name, as stored in tblproducts.servertype. */
    const MODULE_RESELLER = 'paneldnsreseller';

    /** The name plus the pre-1.9.3 spellings, so drift runs even before the migration. */
    const SERVERTYPES = ['paneldnsreseller', 'paneldns-reseller', 'paneldns_reseller'];

    /**
     * DRIFT-404-01: a server whose sub-client lookups include at least this many 404s,
     * and more 404s than other answers, is treated as a token/org mistake.
     */
    const MASS_NOT_FOUND_MIN = 3;

    /** DRIFT-404-01: the admin-notes marker on a service whose sub-client was not found. */
    const NOT_FOUND_MARKER = '[paneldns-drift:not-found]';

    /**
     * DRIFT-SOT-01 (as paneldns-whmcs DRIFT-TRUTH-01): the WHMCS statuses drift sync may
     * write, least to most restrictive. It only moves a service rightwards. A status not
     * listed (Pending, Completed, …) is never changed.
     */
    const STATUS_RANK = ['Active' => 0, 'Suspended' => 1, 'Terminated' => 2];

    /**
     * @internal test seam — when set, called with the server params and the service row
     * instead of building a real API client (which also runs the DNS-based SSRF pre-flight).
     * @var null|\Closure
     */
    public static $apiFactory = null;

    /**
     * Reconcile this module's services.
     */
    public static function run(): array
    {
        $stats = ['checked' => 0, 'drift_fixed' => 0, 'reported' => 0, 'errors' => 0, 'flagged' => 0, 'skipped' => 0];

        try {
            // Only this module's services AND status != Terminated.
            $rows = \WHMCS\Database\Capsule::table('tblhosting')
                ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                // MODULE-NAME-01 (v1.9.3): WHMCS stores the module FOLDER name here; the
                // module is now `paneldnsreseller` (the old `paneldns-reseller` is not a
                // valid WHMCS module name). Legacy spellings are matched until migrated.
                ->whereIn('tblproducts.servertype', self::SERVERTYPES)
                ->whereNotIn('tblhosting.domainstatus', ['Terminated', 'Cancelled', 'Fraud'])
                ->select(
                    'tblhosting.id as service_id',
                    'tblhosting.domainstatus',
                    'tblhosting.dedicatedip as paneldns_id',
                    'tblhosting.server as server_id',
                    'tblhosting.notes',
                    'tblproducts.servertype'
                )
                ->orderBy('tblhosting.lastupdate', 'asc')
                ->limit(self::MAX_PER_RUN)
                ->get();
        } catch (\Throwable $e) {
            // FIX-H9: never leak exception messages — they may contain SQL or credentials.
            return ['error' => get_class($e) . ' (see activity log)'];
        }

        // DRIFT-404-01: per server, the answered lookups, the 404s (flagged only once the
        // run shows they are the exception), and the servers no longer called this run.
        $answered = [];
        $notFound = [];
        $stopped  = [];

        foreach ($rows as $row) {
            $sid = (int) $row->server_id;
            if (isset($stopped[$sid])) {
                $stats['skipped']++;
                continue;
            }
            try {
                $outcome = self::reconcile($row);
                if ($outcome === 'rate_limited') {
                    // As paneldns-whmcs: a 429 stops the run; the next cron carries on.
                    self::log('drift sync stopped early: PanelDNS rate limit', $stats);
                    break;
                }
                $stats['checked']++;

                if ($outcome === 'fixed') {
                    $stats['drift_fixed']++;
                } elseif ($outcome === 'reported') {
                    $stats['reported']++;
                } elseif ($outcome === 'auth') {
                    // A refused token refuses every lookup: stop calling this server.
                    $stopped[$sid] = 'auth';
                    self::log("drift sync stopped for server #{$sid}: PanelDNS refused the API token (HTTP 401/403)."
                        . " No service was changed. Check the server's Access Hash.");
                } elseif ($outcome === 'not_found') {
                    $notFound[$sid][] = $row;
                }

                if (in_array($outcome, ['fixed', 'reported', 'none', 'not_found'], true)) {
                    $answered[$sid] = ($answered[$sid] ?? 0) + 1;
                }
                // Every lookup on this server so far was a 404: stop before calling more.
                if ($outcome === 'not_found'
                    && count($notFound[$sid]) >= self::MASS_NOT_FOUND_MIN
                    && count($notFound[$sid]) === $answered[$sid]) {
                    $stopped[$sid] = 'not_found';
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                // SEC-M8: log class only — getMessage() may contain SQL, credentials, or file paths
                self::log("drift sync exception on service #{$row->service_id}: " . get_class($e));
            }
        }

        foreach ($notFound as $sid => $list) {
            $seen = (int) ($answered[$sid] ?? 0);
            if (self::looksLikeWrongOrg(count($list), $seen, ($stopped[$sid] ?? '') === 'not_found')) {
                self::log('drift sync: ' . count($list) . " of {$seen} sub-clients looked up on server #{$sid}"
                    . ' were not found in PanelDNS. No service was changed or flagged: check that the'
                    . " server's API token belongs to the right PanelDNS organisation.");
                continue;
            }
            foreach ($list as $row) {
                if (self::flagNotFound($row)) $stats['flagged']++;
            }
        }

        self::log("drift sync complete", $stats);
        return $stats;
    }

    /**
     * DRIFT-404-01: are these 404s a token/org mistake rather than deleted sub-clients?
     *
     * @internal public for unit tests.
     */
    public static function looksLikeWrongOrg(int $notFound, int $answered, bool $stoppedEarly): bool
    {
        if ($stoppedEarly) return true;
        return $notFound >= self::MASS_NOT_FOUND_MIN && $notFound * 2 > $answered;
    }

    /**
     * ID-STRICT-01 (v1.9.6, ported from paneldns-whmcs 1.1.4 PanelDnsPlatformModule::parseId):
     * a PanelDNS sub-client id as stored in WHMCS (tblhosting.dedicatedip), or null when
     * the value is not one.
     *
     * Dedicated IP is WHMCS's IP-address field. A loose `(int)` cast read '203.0.113.5' as
     * id 203. Only a plain positive whole number (surrounding whitespace allowed) is an id;
     * the caller treats null as "not linked" and skips the row. Same rule as
     * PanelDnsResellerService::subClientId() (NO-EMAIL-LINK-03).
     *
     * @internal public for unit tests and for the module's hook paths.
     */
    public static function parseId($v): ?int
    {
        if (is_int($v)) return $v > 0 ? $v : null;
        if (!is_string($v)) return null;

        $v = trim($v);
        if ($v === '' || !ctype_digit($v)) return null;

        $id = (int) $v;
        // A value past PHP_INT_MAX would saturate to it: not an id either.
        return $id > 0 && (string) $id === ltrim($v, '0') ? $id : null;
    }

    /**
     * DRIFT-SOT-01: what to do for a WHMCS status and an upstream sub-client status. The
     * same decisions as paneldns-whmcs 1.1.4's reconcile()/tighten():
     *
     *   'upstream:suspend'                     WHMCS Suspended, PanelDNS active: suspend
     *                                          the sub-client again
     *   'whmcs:Suspended' / 'whmcs:Terminated' the upstream status, mapped, is stricter
     *                                          than WHMCS's (STATUS_RANK): write it
     *   'report:<Status>'                      it differs but is not stricter: log
     *                                          "drift reported, not applied"
     *   null                                   in step, or an unknown upstream status
     *
     * Never returns an action that leaves a service less restricted than WHMCS has it.
     *
     * @internal public for unit tests.
     */
    public static function decide(string $whmcsStatus, string $remoteStatus): ?string
    {
        $expected = match ($remoteStatus) {
            'active'    => 'Active',
            'suspended' => 'Suspended',
            'cancelled' => 'Terminated',
            default     => null,
        };
        if ($expected === null || $whmcsStatus === $expected) return null;

        // WHMCS suspended the service; the sub-client must not stay usable.
        if ($whmcsStatus === 'Suspended' && $remoteStatus === 'active') return 'upstream:suspend';

        $stricter = isset(self::STATUS_RANK[$whmcsStatus], self::STATUS_RANK[$expected])
            && self::STATUS_RANK[$expected] > self::STATUS_RANK[$whmcsStatus];
        return ($stricter ? 'whmcs:' : 'report:') . $expected;
    }

    /**
     * @return string 'fixed' | 'reported' | 'none' | 'not_found' | 'auth' | 'rate_limited' | 'error' | 'skip'
     */
    private static function reconcile($row): string
    {
        // ID-STRICT-01: only a plain positive id; anything else in Dedicated IP is skipped.
        $subClientId = self::parseId($row->paneldns_id ?? null);
        if ($subClientId === null) return 'skip';

        // Build server params for the module's API client.
        $serverParams = self::loadServerParams((int) $row->server_id);
        if (!$serverParams) return 'skip';

        if (self::$apiFactory !== null) {
            $api = (self::$apiFactory)($serverParams, $row);
        } else {
            // SEC-H7: SSRF pre-flight — PanelDnsResellerDriftSync builds PanelDnsResellerApi directly (no __construct() guard),
            // so we validate the hostname here before making the connection. A fail-fast
            // only: the enforcing check is the client's pinned pre-flight (SSRF-PIN-01).
            $driftHost     = $serverParams['serverhostname'];
            $driftResolved = gethostbyname($driftHost);
            if (filter_var($driftResolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'skip'; // skip this service — private/reserved server hostname
            }

            $api = new PanelDnsResellerApi(
                ($serverParams['serversecure'] ? 'https' : 'http')
                    . '://' . $driftHost
                    . ($serverParams['serverport'] && !in_array((int) $serverParams['serverport'], [80, 443], true)
                        ? ':' . (int) $serverParams['serverport'] : ''),
                $serverParams['serveraccesshash'] ?? '',
                PanelDnsResellerApi::MODE_RESELLER,
                (bool) $serverParams['serversecure']
            );
        }

        $upstream = $api->getSubClient($subClientId);
        $status   = (int) ($upstream['status'] ?? 0);

        if (!$upstream['ok'] && $status === 429) return 'rate_limited';
        // DRIFT-404-01: never terminate on a 404 — run() decides whether to flag it.
        if (!$upstream['ok'] && $status === 404) return 'not_found';
        if (!$upstream['ok'] && in_array($status, [401, 403], true)) return 'auth';
        if (!$upstream['ok']) return 'error'; // transient error; try next cron

        $serviceId    = (int) $row->service_id;
        $remoteStatus = (string) ($upstream['data']['status'] ?? 'unknown');
        $whmcsStatus  = (string) $row->domainstatus;

        // SEC-L: $remoteStatus is API-sourced; escape before interpolating into log string.
        $safeRemote = htmlspecialchars($remoteStatus, ENT_QUOTES, 'UTF-8');
        $safeWhmcs  = htmlspecialchars($whmcsStatus,  ENT_QUOTES, 'UTF-8');
        $reason     = "drift: upstream={$safeRemote} whmcs={$safeWhmcs}";

        $action = self::decide($whmcsStatus, $remoteStatus);
        if ($action === null) return 'none';

        if ($action === 'upstream:suspend') {
            // DRIFT-SOT-01: WHMCS suspended this service; PanelDNS follows WHMCS.
            $resp = $api->patchSubClient($subClientId, ['status' => 'suspended']);
            if ($resp['ok'] ?? false) {
                self::log('drift fixed: sub-client suspended again in PanelDNS, as in WHMCS (WHMCS is the source of truth)',
                    ['service_id' => $serviceId, 'sub_client_id' => $subClientId, 'reason' => $reason]);
                return 'fixed';
            }
            if ((int) ($resp['status'] ?? 0) === 429) return 'rate_limited';
            self::log('drift: could not suspend the sub-client again in PanelDNS; retried next cron',
                ['service_id' => $serviceId, 'sub_client_id' => $subClientId, 'error' => substr((string) ($resp['error'] ?? ''), 0, 200)]);
            return 'error';
        }

        [$kind, $target] = explode(':', $action, 2);
        if ($kind === 'whmcs') {
            self::updateWhmcsStatus($serviceId, $target, $reason);
            return 'fixed';
        }

        self::log('drift reported, not applied: WHMCS is the source of truth for service status; '
            . 'check the service and the sub-client and change whichever is wrong',
            ['service_id' => $serviceId, 'sub_client_id' => $subClientId, 'would_be' => $target, 'reason' => $reason]);
        return 'reported';
    }

    /**
     * DRIFT-404-01: log a not-found sub-client and mark the service for review. Changes
     * neither status. Returns true when the marker was newly added.
     */
    private static function flagNotFound($row): bool
    {
        $subClientId = (int) self::parseId($row->paneldns_id ?? null); // reconcile() already parsed it
        self::log("drift: sub-client {$subClientId} of service #{$row->service_id} was not found in PanelDNS (HTTP 404)."
            . ' The service was not changed. If the sub-client was deleted in PanelDNS on purpose, terminate the'
            . " service in WHMCS; otherwise check the service's Dedicated IP field.");

        $notes = (string) ($row->notes ?? '');
        if (str_contains($notes, self::NOT_FOUND_MARKER)) return false;
        try {
            \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', (int) $row->service_id)
                ->update(['notes' => ($notes !== '' ? rtrim($notes) . "\n" : '') . self::NOT_FOUND_MARKER]);
        } catch (\Throwable $e) {
            self::log("drift flag failed for #{$row->service_id}: " . get_class($e));
            return false;
        }
        return true;
    }

    private static function loadServerParams(int $serverId): ?array
    {
        try {
            $s = \WHMCS\Database\Capsule::table('tblservers')->where('id', $serverId)->first();
            if (!$s) return null;
            // WHMCS doesn't have a generic per-server port column we use, so we
            // accept the standard ports (443 secure, 80 plain). Operators
            // running PanelDNS on a non-standard port can put it in the
            // hostname field as host:port.
            return [
                'serverhostname'   => $s->hostname,
                'serverport'       => $s->secure ? 443 : 80,
                'serversecure'     => (bool) $s->secure,
                // SEC-H01: accesshash is stored encrypted in tblservers; decrypt
                // before use. Matches PanelDnsResellerHooks::apiForServer() pattern.
                'serveraccesshash' => function_exists('decrypt') ? decrypt($s->accesshash) : (string) $s->accesshash,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function updateWhmcsStatus(int $serviceId, string $newStatus, string $reason): void
    {
        try {
            \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $serviceId)
                ->update([
                    'domainstatus' => $newStatus,
                    'lastupdate'   => date('Y-m-d H:i:s'),
                ]);
            self::log("drift fixed", ['service_id' => $serviceId, 'to' => $newStatus, 'reason' => $reason]);
        } catch (\Throwable $e) {
            // SEC-M8: log class only — getMessage() may contain SQL or credentials
            self::log("drift apply failed for #{$serviceId}: " . get_class($e));
        }
    }

    private static function log($msg, $ctx = []): void
    {
        if (function_exists('logActivity')) {
            logActivity('PanelDNS PanelDnsResellerDriftSync: ' . (is_array($msg) ? json_encode($msg) : $msg)
                . (!empty($ctx) ? ' ' . json_encode($ctx) : ''));
        }
    }
}
}
