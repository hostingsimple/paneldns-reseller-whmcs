<?php

/**
 * PanelDnsResellerHooks — static helper methods for WHMCS hook callbacks.
 *
 * Separated from the main service class so hooks.php stays thin and all
 * database/API logic is testable in isolation.
 *
 * WHMCS hook variable conventions (verified against developers.whmcs.com):
 *   - AfterRegistrarRegistration ("Executes after a successful domain register
 *     command"): variables nested under $vars['params'] (registrar module params):
 *     'userid' (the client who owns the domain), 'domainid', 'sld', 'tld'. Many
 *     registrars also expose a convenience 'domainname' = full domain.
 *   - DomainTransferCompleted ("Executes when a domain transfer is set to completed
 *     by the domain sync cron"): TOP LEVEL — 'domainId', 'domain', 'registrar'… No
 *     user id; the owner is read from tbldomains by id.
 *   - AfterRegistrarTransfer is NOT used (ZONE-OWN-04, v1.9.5): it "Executes upon
 *     completion of the registrar module function. Will execute regardless of success
 *     state" — i.e. when a transfer is submitted, even a failed one, for a domain the
 *     client may not own.
 *   - DomainDelete: variables at TOP LEVEL — 'userid', 'domainid'. No domain
 *     name is passed, so it is resolved from tbldomains by id.
 *   - AddonActivated / AddonUnsuspended / AddonSuspended / AddonTerminated /
 *     AddonCancelled: TOP LEVEL — 'id' (tblhostingaddons.id), 'userid', 'serviceid',
 *     'addonid'. No addon name; resolved from tbladdons.
 *     https://developers.whmcs.com/hooks-reference/addon/
 *   - No invoice hook (PAY-02, v1.9.5). onInvoicePaid() and its InvoicePaymentSuccess
 *     hook (a name WHMCS does not have) were removed: on any payment they re-activated
 *     every suspended service of the client. WHMCS's own Enable Unsuspension calls
 *     UnsuspendAccount for services it suspended for non-payment. See hooks.php.
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
 *  12 Allow Zones for Domains Not in This Account   configoption12 (ZONE-OWN-02, v1.9.5)
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
     * WHMCS domain statuses (tbldomains.status) in which the domain is not, or is no
     * longer, the client's.
     */
    const DOMAIN_ENDED_STATUSES = ['Cancelled', 'Fraud', 'Transferred Away', 'Expired'];

    /**
     * ZONE-OWN-04: AfterRegistrarRegistration — the registrar has just registered the
     * domain for this client.
     */
    public static function onDomainRegistered(array $vars): void
    {
        $p = is_array($vars['params'] ?? null) ? $vars['params'] : [];
        $domain = trim((string) ($p['domainname'] ?? ''));
        if ($domain === '') {
            $sld = rtrim(trim((string) ($p['sld'] ?? '')), '.');
            $tld = ltrim(trim((string) ($p['tld'] ?? '')), '.'); // with or without its dot
            $domain = ($sld !== '' && $tld !== '') ? $sld . '.' . $tld : '';
        }
        self::onDomainEvent((int) ($p['userid'] ?? 0), $domain, 'create');
    }

    /**
     * ZONE-OWN-04: DomainTransferCompleted — WHMCS's domain sync cron has seen the
     * transfer complete. The hook passes no user id; the owner comes from tbldomains.
     */
    public static function onDomainTransferCompleted(array $vars): void
    {
        $domainId = (int) ($vars['domainId'] ?? $vars['domainid'] ?? 0);
        if ($domainId <= 0) return;

        try {
            $row = \WHMCS\Database\Capsule::table('tbldomains')
                ->where('id', $domainId)
                ->select(['userid', 'domain'])
                ->first();
        } catch (\Throwable $e) {
            logActivity('PanelDNS domain transfer hook error (' . get_class($e) . ')');
            return;
        }
        if (!$row) return;

        $domain = strtolower(trim((string) $row->domain));
        $hooked = strtolower(trim((string) ($vars['domain'] ?? '')));
        if ($hooked !== '' && $hooked !== $domain) return; // not the domain the hook reported

        self::onDomainEvent((int) $row->userid, $domain, 'create', true);
    }

    /**
     * ZONE-OWN-01/04: the statuses of this client's tbldomains rows for exactly this
     * domain (lower-cased; an IDN also matches its Unicode spelling). Throws on a DB
     * error — callers refuse rather than guess.
     *
     * @return string[]
     */
    public static function clientDomainStatuses(int $userId, string $domain): array
    {
        $domain = strtolower(trim($domain));
        if ($userId <= 0 || $domain === '') return [];

        $names = [$domain];
        if (str_contains($domain, 'xn--') && function_exists('idn_to_utf8')) {
            $u = idn_to_utf8($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($u) && $u !== '') $names[] = mb_strtolower($u);
        }
        $names = array_values(array_unique($names));

        $rows = \WHMCS\Database\Capsule::table('tbldomains')
            ->where('userid', $userId)
            ->whereIn('domain', $names)
            ->select(['domain', 'status'])
            ->get();

        $statuses = [];
        foreach ($rows as $row) {
            // Exact match here too: MySQL's default collations also equate case and
            // trailing spaces.
            if (in_array(mb_strtolower((string) $row->domain), $names, true)) {
                $statuses[] = (string) $row->status;
            }
        }
        return $statuses;
    }

    /**
     * ZONE-OWN-04: the domain is (or has just become) the client's: a tbldomains row of
     * theirs in a live status. Pending Transfer counts only when the caller is the
     * transfer-completed hook, which is WHMCS saying the transfer is done.
     */
    private static function domainIsTheClients(int $userId, string $domain, bool $transferCompleted): bool
    {
        $refused = $transferCompleted
            ? self::DOMAIN_ENDED_STATUSES
            : array_merge(self::DOMAIN_ENDED_STATUSES, ['Pending Transfer']);
        foreach (self::clientDomainStatuses($userId, $domain) as $status) {
            if (!in_array($status, $refused, true)) return true;
        }
        return false;
    }

    /**
     * DOMAIN-01/02: handle domain create/delete events.
     *
     * Finds the client's active paneldnsreseller service, checks the relevant
     * per-product config option, and creates or deletes the matching DNS zone.
     *
     * ZONE-OWN-04 (v1.9.5): 'create' first checks that the domain is on this client's
     * own WHMCS account. Up to 1.9.4 it was also run from AfterRegistrarTransfer, which
     * fires when a transfer is SUBMITTED (successful or not), so ordering a transfer of
     * someone else's domain created its zone under the orderer's sub-client.
     *
     * @param int    $userId WHMCS client id
     * @param string $domain Full domain name (e.g. example.com)
     * @param string $action 'create' or 'delete'
     * @param bool   $transferCompleted 'create' from DomainTransferCompleted
     */
    public static function onDomainEvent(int $userId, string $domain, string $action, bool $transferCompleted = false): void
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

            if ($action === 'create' && !self::domainIsTheClients($userId, $domain, $transferCompleted)) {
                logActivity("PanelDNS: auto-create zone {$domain} skipped for client #{$userId}"
                    . ' — not a domain on their account (or its transfer has not completed).');
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
     * ADDON-ZONES-01 (v1.9.6): the only predefined-addon names that grant zones, e.g.
     * "PanelDNS +10 Zones". The whole name must match; there is no fallback.
     */
    const ZONE_PACK_PATTERN = '/^\s*PanelDNS\s*\+\s*(\d{1,5})\s*zones?\s*$/i';

    /**
     * ADDON-ZONES-01: while an addon hook runs, the state the addon is moving to
     * (tblhostingaddons.id => active), so the count does not depend on whether WHMCS has
     * written the addon's new status before running the hook.
     *
     * @var array<int,bool>
     */
    public static $addonStateOverride = [];

    /**
     * ADDON-ZONES-01: the zones an addon of this name grants; 0 when it is not a PanelDNS
     * zone pack.
     *
     * @internal public for unit tests.
     */
    public static function zonePackSize(string $addonName): int
    {
        return preg_match(self::ZONE_PACK_PATTERN, $addonName, $m) ? (int) $m[1] : 0;
    }

    /**
     * ADDON-ZONES-01: the extra zones the service's ACTIVE PanelDNS zone-pack addons
     * grant, counted from tblhostingaddons each time. Throws on a DB error: a limit
     * computed without the addons would take zones away.
     */
    public static function addonZones(int $serviceId): int
    {
        if ($serviceId <= 0) return 0;

        $rows = \WHMCS\Database\Capsule::table('tblhostingaddons')
            ->join('tbladdons', 'tblhostingaddons.addonid', '=', 'tbladdons.id')
            ->where('tblhostingaddons.hostingid', $serviceId)
            ->select(['tblhostingaddons.id', 'tblhostingaddons.status', 'tbladdons.name'])
            ->get();

        $zones = 0;
        foreach ($rows as $row) {
            $id     = (int) ($row->id ?? 0);
            $active = self::$addonStateOverride[$id] ?? ((string) ($row->status ?? '') === 'Active');
            if ($active) {
                $zones += self::zonePackSize((string) ($row->name ?? ''));
            }
        }
        return $zones;
    }

    /**
     * ADDON-01 / ADDON-ZONES-01 (v1.9.6): a PanelDNS zone-pack addon on one of this
     * module's services changed state; re-apply the service's limits.
     *
     * The limit is recomputed, not adjusted: product (or "Zone Limit" Configurable
     * Option) limit + every active zone pack on the service (addonZones()), through
     * WHMCS's ModuleChangePackage, i.e. this module's own ChangePackage — the same
     * function CreateAccount and package upgrades use. Up to 1.9.5 every addon on the
     * service, whatever it was, added the number in its name or 5 by default, and
     * suspend/terminate subtracted it again: any cheap addon granted 5 zones, suspend +
     * unsuspend lost zones for good (AddonUnsuspended was not hooked), suspend + terminate
     * subtracted twice, and the next ChangePackage reset the grants.
     *
     * @param int  $serviceId      WHMCS hosting service id (tblhosting.id)
     * @param int  $addonId        predefined addon id (tbladdons.id)
     * @param int  $hostingAddonId the service's addon row (tblhostingaddons.id; the hook's 'id')
     * @param bool $active         the state the addon is moving to
     */
    public static function onAddonChange(int $serviceId, int $addonId, int $hostingAddonId, bool $active): void
    {
        if ($serviceId <= 0 || $addonId <= 0) {
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

            // Resolve the addon name (the hook does not pass it). Any other addon on the
            // service (support, SSL, a free extra) changes nothing.
            $addonName = (string) \WHMCS\Database\Capsule::table('tbladdons')
                ->where('id', $addonId)
                ->value('name');
            if (self::zonePackSize($addonName) <= 0) {
                return;
            }

            self::$addonStateOverride = $hostingAddonId > 0 ? [$hostingAddonId => $active] : [];
            try {
                $result = localAPI('ModuleChangePackage', ['serviceid' => $serviceId]);
            } finally {
                self::$addonStateOverride = [];
            }
            if (($result['result'] ?? '') !== 'success') {
                logActivity("PanelDNS: re-applying the zone limit of service #{$serviceId} after an addon change failed: "
                    . substr((string) ($result['message'] ?? 'unknown error'), 0, 200));
            }
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

                // NO-EMAIL-LINK-02 (v1.9.4): CreateAccount no longer re-links a re-order to
                // the client's old sub-client, so that is now an admin step (Dedicated IP +
                // Create) that leaves this old service's marker in place. If a live service
                // of this module now uses the sub-client, keep it and drop the stale marker.
                $liveId = $subClientId > 0
                    ? self::liveServiceUsing($subClientId, (int) $svc->service_id, (string) $svc->server_hostname)
                    : null;
                if ($liveId !== null) {
                    try {
                        \WHMCS\Database\Capsule::table('tblhosting')
                            ->where('id', $svc->service_id)
                            ->update(['notes' => self::stripGraceMarker((string) $svc->notes)]);
                    } catch (\Throwable $e) { /* swallow — retried next cron, still guarded */ }
                    logActivity("PanelDNS: grace period expired for service #{$svc->service_id}, but sub-client {$subClientId}"
                        . " is now used by live service #{$liveId} — not deleted; grace marker removed.");
                    continue;
                }

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
     * The id of another not-ended paneldnsreseller service on the same PanelDNS host whose
     * Dedicated IP is this sub-client, or null. Throws on a DB error so the caller's
     * per-run catch leaves every marker in place rather than deleting on a failed check.
     */
    private static function liveServiceUsing(int $subClientId, int $excludeServiceId, string $hostname): ?int
    {
        $row = \WHMCS\Database\Capsule::table('tblhosting')
            ->join('tblservers',  'tblhosting.server',    '=', 'tblservers.id')
            ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
            ->whereIn('tblproducts.servertype', self::SERVERTYPES)
            ->where('tblhosting.dedicatedip', (string) $subClientId)
            ->where('tblhosting.id', '!=', $excludeServiceId)
            ->whereNotIn('tblhosting.domainstatus', ['Terminated', 'Cancelled', 'Fraud'])
            ->where('tblservers.hostname', $hostname)
            ->select(['tblhosting.id'])
            ->first();
        return $row ? (int) $row->id : null;
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
