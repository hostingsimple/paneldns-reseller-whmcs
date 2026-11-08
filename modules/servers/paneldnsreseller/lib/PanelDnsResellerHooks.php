<?php

/**
 * PanelDnsResellerHooks — static helper methods for WHMCS hook callbacks.
 *
 * Separated from the main service class so hooks.php stays thin and all
 * database/API logic is testable in isolation.
 *
 * WHMCS hook variable conventions (verified against developers.whmcs.com):
 *   - AfterRegistrarRegistration / AfterRegistrarTransfer: variables nested
 *     under $vars['params'] (registrar module params). Domain is split into
 *     'sld' + 'tld' (tld includes the leading dot); 'userid' is the client.
 *     Many registrars also expose a convenience 'domainname' = full domain.
 *   - DomainDelete: variables at TOP LEVEL — 'userid', 'domainid'. No domain
 *     name is passed, so it is resolved from tbldomains by id.
 *   - AddonActivated / AddonSuspended / AddonTerminated: TOP LEVEL — 'id',
 *     'userid', 'serviceid', 'addonid'. No addon name; resolved from tbladdons.
 *
 * Module config options (defined in paneldnsreseller_ConfigOptions) are stored
 * per-product in tblproducts.configoptionN by definition order, NOT in the
 * Configurable Options tables. For yesno options the stored value is 'on' / ''.
 * Definition order at time of writing:
 *   1 Zone Limit                          configoption1
 *   2 Max Records Per Zone                configoption2
 *   3 Send Welcome Email                  configoption3
 *   4 NS1 Hostname                        configoption4
 *   5 NS2 Hostname                        configoption5
 *   6 NS3 Hostname                        configoption6
 *   7 NS4 Hostname                        configoption7
 *   8 SOA Email                           configoption8
 *   9 Auto-Create Zone on Domain Order    configoption9
 *  10 Auto-Delete Zone on Domain Expiry   configoption10
 *  11 Termination Grace Period (Days)     configoption11
 *
 * MODULE-NAME-01 (v1.9.3): every query filters on tblproducts.servertype IN SERVERTYPES.
 * Up to 1.9.2 they filtered on 'paneldns_reseller', a name no product carried (WHMCS
 * stores the folder name, then 'paneldns-reseller'), so none of these hooks ever acted.
 */

if (!defined('WHMCS')) {
    die('Access denied.');
}

require_once __DIR__ . '/PanelDnsApi.php';

class PanelDnsResellerHooks
{
    /** WHMCS module name (the folder name) — tblproducts.servertype. */
    const MODULE = 'paneldnsreseller';

    /**
     * Every servertype that belongs to this module: the name plus the pre-1.9.3
     * spellings (PanelDnsResellerMigration::LEGACY_NAMES), so a hook still finds its
     * services before the migration has run. Never a platform-module name.
     */
    const SERVERTYPES = ['paneldnsreseller', 'paneldns-reseller', 'paneldns_reseller'];

    /**
     * @internal test seam — when set, called with the joined server row instead of
     * building a real API client (which also runs the DNS-based SSRF pre-flight).
     * @var null|\Closure
     */
    public static $apiFactory = null;

    /**
     * DOMAIN-01/02: handle domain create/delete events.
     *
     * Finds the client's active paneldnsreseller service, checks the relevant
     * per-product config option, and creates or deletes the matching DNS zone.
     *
     * @param int    $userId WHMCS client id
     * @param string $domain Full domain name (e.g. example.com)
     * @param string $action 'create' or 'delete'
     */
    public static function onDomainEvent(int $userId, string $domain, string $action): void
    {
        $domain = strtolower(trim($domain));
        if ($userId <= 0 || $domain === '') {
            return;
        }

        try {
            // Find an active paneldnsreseller service for this client, pulling
            // the two domain-automation config options from the product row.
            $service = \WHMCS\Database\Capsule::table('tblhosting')
                ->join('tblservers', 'tblhosting.server', '=', 'tblservers.id')
                ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                ->where('tblhosting.userid', $userId)
                ->where('tblhosting.domainstatus', 'Active')
                ->whereIn('tblproducts.servertype', self::SERVERTYPES)
                ->select([
                    'tblhosting.id as service_id',
                    'tblhosting.dedicatedip as sub_client_id',
                    'tblservers.hostname as server_hostname',
                    'tblservers.accesshash as server_key',
                    'tblservers.secure as server_secure',
                    'tblservers.port as server_port',
                    'tblproducts.configoption9 as auto_create',
                    'tblproducts.configoption10 as auto_delete',
                ])
                ->first();

            if (!$service) {
                return;
            }

            $subClientId = (int) $service->sub_client_id;
            if ($subClientId <= 0) {
                return;
            }

            // yesno module config options store 'on' when ticked ('' when not).
            if ($action === 'create' && !self::isYes($service->auto_create)) {
                return;
            }
            if ($action === 'delete' && !self::isYes($service->auto_delete)) {
                return;
            }

            $api = self::apiForServer($service);

            if ($action === 'create') {
                $resp = $api->createZone($domain, $subClientId);
                if (!($resp['ok'] ?? false)) {
                    // FIX-M20: cap API error strings in logActivity to prevent log injection.
                    logActivity("PanelDNS: auto-create zone {$domain} for sub-client {$subClientId} failed: " . substr((string) ($resp['error'] ?? 'unknown'), 0, 200));
                }
                return;
            }

            // delete: locate the zone by exact name + sub-client, then delete it.
            // PAGING-01: `name` is an exact-match filter on current PanelDNS; an older
            // server ignores it and lists the sub-client's zones 50 at a time, so the
            // pages are followed and the exact-match check below still decides.
            $zones = $api->getAllPages('/api/v1/zones', ['name' => $domain, 'sub_client_id' => $subClientId], 20);
            foreach ($zones['data'] as $zone) {
                if (strtolower((string) ($zone['name'] ?? '')) === $domain
                    && (int) ($zone['sub_client_id'] ?? 0) === $subClientId) {
                    $del = $api->delete('/api/v1/zones/' . (int) ($zone['id'] ?? 0));
                    if (!($del['ok'] ?? false)) {
                        logActivity("PanelDNS: auto-delete zone {$domain} for sub-client {$subClientId} failed: " . substr((string) ($del['error'] ?? 'unknown'), 0, 200));
                    }
                    break;
                }
            }
        } catch (\Throwable $e) {
            // SEC-L06: log class only — exception messages may contain PII or SQL fragments.
            logActivity('PanelDNS domain hook error (' . get_class($e) . ')');
        }
    }

    /**
     * A yes/no module option is on. WHMCS stores a ticked box as 'on'; 'yes' (the
     * ConfigOptions default) and '1' are accepted too (WELCOME-01).
     *
     * @internal public for unit tests.
     */
    public static function isYes($value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['on', 'yes', '1', 'true'], true);
    }

    /**
     * ADDON-01: adjust a sub-client's zone limit when a product addon is
     * activated (+) or suspended/terminated (-).
     *
     * The number of extra zones is parsed from the addon's name in tbladdons
     * (e.g. "PanelDNS +10 Zones" → 10). Falls back to 5 if no number is found.
     *
     * @param int $serviceId WHMCS hosting service id (tblhosting.id)
     * @param int $addonId   Predefined addon id (tbladdons.id)
     * @param int $direction +1 to grant, -1 to revoke
     */
    public static function onAddonChange(int $serviceId, int $addonId, int $direction): void
    {
        if ($serviceId <= 0) {
            return;
        }

        try {
            $service = \WHMCS\Database\Capsule::table('tblhosting')
                ->join('tblservers', 'tblhosting.server', '=', 'tblservers.id')
                ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                ->where('tblhosting.id', $serviceId)
                ->whereIn('tblproducts.servertype', self::SERVERTYPES)
                ->select([
                    'tblhosting.dedicatedip as sub_client_id',
                    'tblservers.hostname as server_hostname',
                    'tblservers.accesshash as server_key',
                    'tblservers.secure as server_secure',
                    'tblservers.port as server_port',
                ])
                ->first();

            if (!$service) {
                return;
            }

            $subClientId = (int) $service->sub_client_id;
            if ($subClientId <= 0) {
                return;
            }

            // Resolve the addon name (the hook does not pass it).
            $addonName = '';
            if ($addonId > 0) {
                $addonName = (string) \WHMCS\Database\Capsule::table('tbladdons')
                    ->where('id', $addonId)
                    ->value('name');
            }

            preg_match('/\+?\s*(\d+)\s*zone/i', $addonName, $m);
            $extraZones = isset($m[1]) ? (int) $m[1] : 5;

            $api = self::apiForServer($service);

            $current = $api->getSubClient($subClientId);
            if (!($current['ok'] ?? false)) {
                return;
            }

            $currentLimit = (int) ($current['data']['zone_limit'] ?? 0);
            $newLimit     = max(0, $currentLimit + ($direction * $extraZones));

            $api->patchSubClient($subClientId, ['zone_limit' => $newLimit]);
        } catch (\Throwable $e) {
            // SEC-L06: log class only — exception messages may contain PII or SQL fragments.
            logActivity('PanelDNS addon hook error (' . get_class($e) . ')');
        }
    }

    /**
     * CLIENT-SYNC-01: push name changes from WHMCS to PanelDNS.
     *
     * Called from the ClientEdit hook whenever a WHMCS client's profile is updated.
     * PATCHes the sub-client of every Active/Suspended paneldnsreseller service the
     * client has. Silently no-ops if the client has no PanelDNS service.
     *
     * CLIENT-SYNC-02 (v1.9.3): sends `name` only. Up to 1.9.2 this also sent `email`
     * and `locale`; PanelDNS's PATCH /api/v1/sub-clients/{id} validates name, status,
     * zone_limit and max_records and drops everything else (the email is fixed as the
     * sub-client's SSO identity), so those two were silently ignored.
     */
    public static function onClientEdit(int $userId, string $firstName, string $lastName): void
    {
        if ($userId <= 0) return;

        $patch = self::clientEditPatch($firstName, $lastName);
        if ($patch === []) return;

        try {
            $services = \WHMCS\Database\Capsule::table('tblhosting')
                ->join('tblservers',  'tblhosting.server',    '=', 'tblservers.id')
                ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                ->where('tblhosting.userid', $userId)
                ->whereIn('tblhosting.domainstatus', ['Active', 'Suspended'])
                ->whereIn('tblproducts.servertype', self::SERVERTYPES)
                ->select([
                    'tblhosting.dedicatedip as sub_client_id',
                    'tblservers.hostname as server_hostname',
                    'tblservers.accesshash as server_key',
                    'tblservers.secure as server_secure',
                    'tblservers.port as server_port',
                ])
                ->get();

            foreach ($services as $service) {
                $subClientId = (int) $service->sub_client_id;
                if ($subClientId <= 0) continue;
                $resp = self::apiForServer($service)->patchSubClient($subClientId, $patch);
                if (!($resp['ok'] ?? false)) {
                    logActivity('PanelDNS client sync failed for sub-client ' . $subClientId . ': '
                        . substr((string) ($resp['error'] ?? 'unknown'), 0, 200));
                }
            }
        } catch (\Throwable $e) {
            logActivity('PanelDNS client sync error (' . get_class($e) . ')');
        }
    }

    /**
     * The PATCH body for a profile change — only fields PanelDNS accepts.
     *
     * @internal public for unit tests.
     * @return array<string,string>
     */
    public static function clientEditPatch(string $firstName, string $lastName): array
    {
        $name = trim((string) preg_replace('/\s+/', ' ', "{$firstName} {$lastName}"));
        if ($name === '') return [];
        // PanelDNS validates name max:128.
        return ['name' => mb_substr($name, 0, 128)];
    }

    /**
     * GRACE-02: delete sub-clients whose grace period has expired.
     *
     * Runs nightly from the DailyCronJob hook. Looks for Terminated paneldnsreseller
     * services with a [paneldns-grace:YYYY-MM-DD] marker in tblhosting.notes where the
     * deadline date is today or in the past, then hard-deletes the sub-client and
     * removes the marker.
     *
     * Terminated WHMCS services are excluded from PanelDnsResellerDriftSync, so the
     * suspended sub-client is never accidentally re-activated before deletion.
     */
    public static function processExpiredGracePeriods(): void
    {
        $today = date('Y-m-d');

        try {
            // GRACE-03 (v1.9.3): filter to THIS module. The query had no servertype
            // filter, so it could pick up a platform-module (or any other) Terminated
            // service carrying the marker text and send a DELETE for whatever id sat in
            // its dedicatedip field.
            $services = \WHMCS\Database\Capsule::table('tblhosting')
                ->join('tblservers',  'tblhosting.server',    '=', 'tblservers.id')
                ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                ->whereIn('tblproducts.servertype', self::SERVERTYPES)
                ->where('tblhosting.domainstatus', 'Terminated')
                ->where('tblhosting.notes', 'like', '%[paneldns-grace:%')
                ->select([
                    'tblhosting.id as service_id',
                    'tblhosting.dedicatedip as sub_client_id',
                    'tblhosting.notes',
                    'tblservers.hostname as server_hostname',
                    'tblservers.accesshash as server_key',
                    'tblservers.secure as server_secure',
                    'tblservers.port as server_port',
                ])
                ->get();

            foreach ($services as $svc) {
                $deadline = self::graceDeadline((string) $svc->notes);
                if ($deadline === null || $deadline > $today) continue; // no marker / not yet expired

                $subClientId = (int) $svc->sub_client_id;
                if ($subClientId > 0) {
                    try {
                        $resp = self::apiForServer($svc)->deleteSubClient($subClientId);
                    } catch (\Throwable $e) {
                        logActivity('PanelDNS grace delete failed for service #' . $svc->service_id
                            . ' (' . get_class($e) . ')');
                        continue; // leave marker in place so the next cron retries
                    }
                    // GRACE-04 (v1.9.3): the API result used to be ignored, so a refused
                    // or failed delete still cleared the marker and the sub-client was
                    // never deleted. The marker is now cleared only on success (or 404,
                    // already gone).
                    if (!self::graceDeleteSucceeded($resp)) {
                        logActivity('PanelDNS grace delete failed for sub-client ' . $subClientId
                            . ' (service #' . $svc->service_id . '): '
                            . substr((string) ($resp['error'] ?? 'unknown'), 0, 200) . ' Will retry on the next cron.');
                        continue;
                    }
                }

                // Remove only the marker — the rest of the notes belong to the admin.
                try {
                    \WHMCS\Database\Capsule::table('tblhosting')
                        ->where('id', $svc->service_id)
                        ->update(['notes' => self::stripGraceMarker((string) $svc->notes)]);
                } catch (\Throwable $e) { /* swallow */ }

                logActivity("PanelDNS: grace period expired, deleted sub-client {$subClientId}"
                    . " (service #{$svc->service_id})");
            }
        } catch (\Throwable $e) {
            logActivity('PanelDNS grace period cron error (' . get_class($e) . ')');
        }
    }

    /**
     * The YYYY-MM-DD deadline from a [paneldns-grace:…] marker, or null.
     *
     * @internal public for unit tests.
     */
    public static function graceDeadline(string $notes): ?string
    {
        return preg_match('/\[paneldns-grace:(\d{4}-\d{2}-\d{2})\]/', $notes, $m) ? $m[1] : null;
    }

    /**
     * The notes with any grace marker removed.
     *
     * @internal public for unit tests.
     */
    public static function stripGraceMarker(string $notes): string
    {
        $out = (string) preg_replace('/\[paneldns-grace:[^\]]*\]/', '', $notes);
        return trim((string) preg_replace("/(\r?\n){3,}/", "\n\n", $out));
    }

    /**
     * The notes with a grace marker appended (replacing any earlier one). The terminate
     * path used to overwrite the whole notes field with the marker.
     *
     * @internal public for unit tests.
     */
    public static function withGraceMarker(string $notes, string $deadline): string
    {
        $kept = self::stripGraceMarker($notes);
        return ($kept !== '' ? $kept . "\n" : '') . "[paneldns-grace:{$deadline}]";
    }

    /**
     * A grace delete is done when PanelDNS deleted the sub-client, or reports that it
     * no longer exists (404 — e.g. the reseller removed it by hand).
     *
     * @internal public for unit tests.
     */
    public static function graceDeleteSucceeded(array $resp): bool
    {
        return !empty($resp['ok']) || (int) ($resp['status'] ?? 0) === 404;
    }

    /**
     * PAY-01: unsuspend all Suspended paneldnsreseller services for a client
     * when they pay an invoice.
     *
     * Deliberately unsuspends regardless of why the service is suspended in WHMCS —
     * if it was suspended by the operator for a non-payment reason, the next
     * DailyCronJob drift sync will re-suspend it. Best-effort per service: one
     * failure does not prevent the others from being processed.
     *
     * @param int $userId WHMCS client id from the InvoicePaymentSuccess hook
     */
    public static function onInvoicePaid(int $userId): void
    {
        if ($userId <= 0) return;

        try {
            $services = \WHMCS\Database\Capsule::table('tblhosting')
                ->join('tblservers',  'tblhosting.server',    '=', 'tblservers.id')
                ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                ->where('tblhosting.userid', $userId)
                ->where('tblhosting.domainstatus', 'Suspended')
                ->whereIn('tblproducts.servertype', self::SERVERTYPES)
                ->select([
                    'tblhosting.id as service_id',
                    'tblhosting.dedicatedip as sub_client_id',
                    'tblservers.hostname as server_hostname',
                    'tblservers.accesshash as server_key',
                    'tblservers.secure as server_secure',
                    'tblservers.port as server_port',
                ])
                ->get()->all();
        } catch (\Throwable $e) {
            logActivity('PanelDNS InvoicePaid: DB query failed (' . get_class($e) . ')');
            return;
        }

        foreach ($services as $svc) {
            $subClientId = (int) $svc->sub_client_id;
            if ($subClientId <= 0) continue;

            try {
                $api  = self::apiForServer($svc);
                $resp = $api->patchSubClient($subClientId, ['status' => 'active']);
                if ($resp['ok']) {
                    // Mirror the status change in WHMCS so the service shows Active.
                    \WHMCS\Database\Capsule::table('tblhosting')
                        ->where('id', $svc->service_id)
                        ->update(['domainstatus' => 'Active']);
                    logActivity("PanelDNS: unsuspended sub-client {$subClientId} (service #{$svc->service_id}) on invoice payment.");
                } else {
                    logActivity('PanelDNS InvoicePaid: unsuspend failed for sub-client '
                        . $subClientId . ' — ' . substr((string) ($resp['error'] ?? 'unknown'), 0, 200));
                }
            } catch (\Throwable $e) {
                logActivity('PanelDNS InvoicePaid: error for service #' . $svc->service_id
                    . ' (' . get_class($e) . ')');
            }
        }
    }

    /**
     * Build a reseller-mode PanelDnsResellerApi from a joined server row.
     * Expects ->server_hostname, ->server_key (encrypted accesshash),
     * ->server_secure, ->server_port.
     */
    private static function apiForServer(object $service): PanelDnsResellerApi
    {
        if (self::$apiFactory !== null) {
            return (self::$apiFactory)($service);
        }

        $secure = (bool) $service->server_secure;
        $port   = (int) $service->server_port;

        // SEC-H7: SSRF pre-flight — validate server hostname before building the API client.
        // This path bypasses __construct() and constructs PanelDnsResellerApi directly.
        $hookResolved = gethostbyname($service->server_hostname);
        if (filter_var($hookResolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new \RuntimeException('paneldnsreseller: server hostname resolves to a private/reserved IP address.');
        }

        $baseUrl = ($secure ? 'https' : 'http')
            . '://' . $service->server_hostname
            . ($port && !in_array($port, [80, 443], true) ? ':' . $port : '');

        return new PanelDnsResellerApi(
            $baseUrl,
            function_exists('decrypt') ? decrypt($service->server_key) : (string) $service->server_key,
            PanelDnsResellerApi::MODE_RESELLER,
            $secure
        );
    }
}
