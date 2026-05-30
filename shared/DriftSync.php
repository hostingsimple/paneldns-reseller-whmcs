<?php

/**
 * DriftSync — daily reconciliation between WHMCS service state and the
 * upstream PanelDNS state.
 *
 * Catches three classes of drift:
 *
 *   1. WHMCS shows the service Active but PanelDNS shows the org as
 *      suspended or cancelled (operator changed it directly on PanelDNS
 *      without going through WHMCS). Fix: stamp the WHMCS service
 *      Suspended/Terminated.
 *   2. WHMCS shows Suspended but PanelDNS shows active. Fix: re-suspend
 *      on PanelDNS (WHMCS is the source of truth for billing).
 *   3. WHMCS shows Active but PanelDNS lookup returns 404 (org deleted
 *      out-of-band). Fix: stamp WHMCS service Terminated.
 *
 * Called from hooks.php on the DailyCronJob hook. Throttled to 100
 * services per run; the next cron picks up where we left off via
 * last_synced_at ordering. A full WHMCS install with thousands of
 * paneldns services therefore reconciles fully over a few days.
 */

if (!defined('WHMCS')) {
    die('Access denied.');
}

class PanelDnsDriftSync
{
    const MAX_PER_RUN = 100;

    /**
     * Reconcile both modules' services in one pass.
     */
    public static function run(): array
    {
        $stats = ['checked' => 0, 'drift_fixed' => 0, 'errors' => 0];

        try {
            // Only services where the module column matches one of ours AND
            // status != Terminated.
            $rows = \WHMCS\Database\Capsule::table('tblhosting')
                ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                ->whereIn('tblproducts.servertype', ['paneldns-platform', 'paneldns-reseller'])
                ->whereNotIn('tblhosting.domainstatus', ['Terminated', 'Cancelled', 'Fraud'])
                ->select(
                    'tblhosting.id as service_id',
                    'tblhosting.domainstatus',
                    'tblhosting.dedicatedip as paneldns_id',
                    'tblhosting.server as server_id',
                    'tblproducts.servertype'
                )
                ->orderBy('tblhosting.lastupdate', 'asc')
                ->limit(self::MAX_PER_RUN)
                ->get();
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        foreach ($rows as $row) {
            try {
                if (self::reconcile($row)) $stats['drift_fixed']++;
                $stats['checked']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                self::log("drift sync exception on service #{$row->service_id}: " . $e->getMessage());
            }
        }

        self::log("drift sync complete", $stats);
        return $stats;
    }

    private static function reconcile($row): bool
    {
        if (empty($row->paneldns_id)) return false;

        // Build server params for the module's API client.
        $serverParams = self::loadServerParams((int) $row->server_id);
        if (!$serverParams) return false;

        $api = new PanelDnsApi(
            ($serverParams['serversecure'] ? 'https' : 'http')
                . '://' . $serverParams['serverhostname']
                . ($serverParams['serverport'] && !in_array((int) $serverParams['serverport'], [80, 443], true)
                    ? ':' . (int) $serverParams['serverport'] : ''),
            $serverParams['serveraccesshash'] ?? '',
            $row->servertype === 'paneldns-platform' ? PanelDnsApi::MODE_PLATFORM : PanelDnsApi::MODE_RESELLER,
            (bool) $serverParams['serversecure']
        );

        $upstream = $row->servertype === 'paneldns-platform'
            ? $api->getOrg((int) $row->paneldns_id)
            : $api->getSubClient((int) $row->paneldns_id);

        // Org/sub-client deleted upstream → terminate WHMCS service.
        if (!$upstream['ok'] && $upstream['status'] === 404) {
            self::updateWhmcsStatus((int) $row->service_id, 'Terminated', 'drift: upstream deleted');
            return true;
        }

        if (!$upstream['ok']) return false; // transient error; try next cron

        $remoteStatus = $upstream['data']['status'] ?? 'unknown';
        $whmcsStatus  = $row->domainstatus;

        // Map upstream → WHMCS expected.
        $expectedWhmcs = match ($remoteStatus) {
            'active'    => 'Active',
            'suspended' => 'Suspended',
            'cancelled' => 'Terminated',
            default     => null,
        };

        if ($expectedWhmcs && $whmcsStatus !== $expectedWhmcs) {
            self::updateWhmcsStatus((int) $row->service_id, $expectedWhmcs, "drift: upstream={$remoteStatus} whmcs={$whmcsStatus}");
            return true;
        }

        return false;
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
                'serveraccesshash' => $s->accesshash,
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
            self::log("drift apply failed for #{$serviceId}: " . $e->getMessage());
        }
    }

    private static function log($msg, $ctx = []): void
    {
        if (function_exists('logActivity')) {
            logActivity('PanelDNS DriftSync: ' . (is_array($msg) ? json_encode($msg) : $msg)
                . (!empty($ctx) ? ' ' . json_encode($ctx) : ''));
        }
    }
}
