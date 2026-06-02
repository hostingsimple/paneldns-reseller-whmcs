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
 * Module config options (defined in paneldns_reseller_ConfigOptions) are stored
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
 */

if (!defined('WHMCS')) {
    die('Access denied.');
}

require_once __DIR__ . '/PanelDnsApi.php';

class PanelDnsResellerHooks
{
    /**
     * DOMAIN-01/02: handle domain create/delete events.
     *
     * Finds the client's active paneldns-reseller service, checks the relevant
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
            // Find an active paneldns-reseller service for this client, pulling
            // the two domain-automation config options from the product row.
            $service = \WHMCS\Database\Capsule::table('tblhosting')
                ->join('tblservers', 'tblhosting.server', '=', 'tblservers.id')
                ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                ->where('tblhosting.userid', $userId)
                ->where('tblhosting.domainstatus', 'Active')
                ->where('tblproducts.servertype', 'paneldns_reseller')
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

            // yesno module config options store 'on' when enabled.
            if ($action === 'create' && strtolower((string) $service->auto_create) !== 'on') {
                return;
            }
            if ($action === 'delete' && strtolower((string) $service->auto_delete) !== 'on') {
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
            $zones = $api->get('/api/v1/zones', ['search' => $domain, 'sub_client_id' => $subClientId]);
            if (($zones['ok'] ?? false) && !empty($zones['data'])) {
                foreach ($zones['data'] as $zone) {
                    if (strtolower((string) ($zone['name'] ?? '')) === $domain
                        && (int) ($zone['sub_client_id'] ?? 0) === $subClientId) {
                        $api->delete('/api/v1/zones/' . (int) ($zone['id'] ?? 0));
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            // SEC-L06: log class only — exception messages may contain PII or SQL fragments.
            logActivity('PanelDNS domain hook error (' . get_class($e) . ')');
        }
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
                ->where('tblproducts.servertype', 'paneldns_reseller')
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
     * WHMCS language name → ISO locale code map.
     * Keys are lowercased values of tblclients.language.
     * PanelDNS supported locales (as of v3.21): en, es, fr, de, pt, zh_Hans, zh_Hant.
     */
    private const LOCALE_MAP = [
        'english'            => 'en',
        'spanish'            => 'es',
        'french'             => 'fr',
        'german'             => 'de',
        'portuguese'         => 'pt',
        'brazilian'          => 'pt',     // WHMCS "Brazilian Portuguese"
        'chinese'            => 'zh_Hans',
        'chinesesimp'        => 'zh_Hans',
        'chinesetrad'        => 'zh_Hant',
    ];

    /**
     * CLIENT-SYNC-01: push name/email/locale changes from WHMCS to PanelDNS.
     *
     * Called from the ClientEdit hook whenever a WHMCS client's profile is
     * updated. Finds the client's active paneldns-reseller service and PATCHes
     * the sub-client record. Silently no-ops if the client has no PanelDNS service.
     *
     * LOCALE-01: also syncs the WHMCS interface language to the sub-client's
     * portal locale so the PanelDNS portal renders in the client's language
     * without them needing to set it manually in the portal.
     */
    public static function onClientEdit(int $userId, string $firstName, string $lastName, string $email, string $language = ''): void
    {
        if ($userId <= 0) return;

        try {
            $service = \WHMCS\Database\Capsule::table('tblhosting')
                ->join('tblservers',  'tblhosting.server',    '=', 'tblservers.id')
                ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                ->where('tblhosting.userid', $userId)
                ->whereIn('tblhosting.domainstatus', ['Active', 'Suspended'])
                ->where('tblproducts.servertype', 'paneldns_reseller')
                ->select([
                    'tblhosting.dedicatedip as sub_client_id',
                    'tblservers.hostname as server_hostname',
                    'tblservers.accesshash as server_key',
                    'tblservers.secure as server_secure',
                    'tblservers.port as server_port',
                ])
                ->first();

            if (!$service) return;

            $subClientId = (int) $service->sub_client_id;
            if ($subClientId <= 0) return;

            $name = trim("{$firstName} {$lastName}");

            // LOCALE-01: map WHMCS language name to ISO locale code.
            $locale = self::LOCALE_MAP[strtolower(trim($language))] ?? null;

            $patch = array_filter([
                'name'   => $name !== '' ? $name : null,
                'email'  => $email !== '' ? $email : null,
                'locale' => $locale,
            ], fn ($v) => $v !== null);

            if (empty($patch)) return;

            self::apiForServer($service)->patchSubClient($subClientId, $patch);
        } catch (\Throwable $e) {
            logActivity('PanelDNS client sync error (' . get_class($e) . ')');
        }
    }

    /**
     * GRACE-02: delete sub-clients whose grace period has expired.
     *
     * Runs nightly from the DailyCronJob hook. Looks for Terminated WHMCS
     * services with a [paneldns-grace:YYYY-MM-DD] marker in tblhosting.notes
     * where the deadline date is today or in the past, then hard-deletes the
     * sub-client and clears the marker.
     *
     * Terminated WHMCS services are excluded from DriftSync, so the suspended
     * sub-client is never accidentally re-activated before deletion.
     */
    public static function processExpiredGracePeriods(): void
    {
        $today = date('Y-m-d');

        try {
            $services = \WHMCS\Database\Capsule::table('tblhosting')
                ->join('tblservers', 'tblhosting.server', '=', 'tblservers.id')
                ->where('tblhosting.domainstatus', 'Terminated')
                ->where('tblhosting.notes', 'like', '[paneldns-grace:%')
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
                if (!preg_match('/\[paneldns-grace:(\d{4}-\d{2}-\d{2})\]/', (string) $svc->notes, $m)) {
                    continue;
                }
                if ($m[1] > $today) continue; // not yet expired

                $subClientId = (int) $svc->sub_client_id;
                if ($subClientId > 0) {
                    try {
                        self::apiForServer($svc)->deleteSubClient($subClientId);
                    } catch (\Throwable $e) {
                        logActivity('PanelDNS grace delete failed for service #' . $svc->service_id
                            . ' (' . get_class($e) . ')');
                        continue; // leave marker in place so the next cron retries
                    }
                }

                // Clear the grace marker to prevent double-processing.
                try {
                    \WHMCS\Database\Capsule::table('tblhosting')
                        ->where('id', $svc->service_id)
                        ->update(['notes' => '']);
                } catch (\Throwable $e) { /* swallow */ }

                logActivity("PanelDNS: grace period expired, deleted sub-client {$subClientId}"
                    . " (service #{$svc->service_id})");
            }
        } catch (\Throwable $e) {
            logActivity('PanelDNS grace period cron error (' . get_class($e) . ')');
        }
    }

    /**
     * PAY-01: unsuspend all Suspended paneldns-reseller services for a client
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
                ->where('tblproducts.servertype', 'paneldns_reseller')
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
     * Build a reseller-mode PanelDnsApi from a joined server row.
     * Expects ->server_hostname, ->server_key (encrypted accesshash),
     * ->server_secure, ->server_port.
     */
    private static function apiForServer(object $service): PanelDnsApi
    {
        $secure = (bool) $service->server_secure;
        $port   = (int) $service->server_port;

        $baseUrl = ($secure ? 'https' : 'http')
            . '://' . $service->server_hostname
            . ($port && !in_array($port, [80, 443], true) ? ':' . $port : '');

        return new PanelDnsApi(
            $baseUrl,
            function_exists('decrypt') ? decrypt($service->server_key) : (string) $service->server_key,
            PanelDnsApi::MODE_RESELLER,
            $secure
        );
    }
}
