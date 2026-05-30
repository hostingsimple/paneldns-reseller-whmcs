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
require_once __DIR__ . '/LicenceCheck.php';
require_once __DIR__ . '/WelcomeMail.php';
require_once __DIR__ . '/EmbeddedDnsManager.php';

class PanelDnsResellerService
{
    public static function run(string $action, array $params): string
    {
        try {
            return (new self($params))->{$action}();
        } catch (\Throwable $e) {
            return 'paneldns-reseller: ' . $e->getMessage();
        }
    }

    /** @var array */ private $params;
    /** @var PanelDnsApi */ private $api;

    private function __construct(array $params)
    {
        $this->params = $params;

        $baseUrl = ($params['serversecure'] ? 'https' : 'http')
            . '://' . ($params['serverhostname'] ?? 'localhost')
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

        $resp = $this->api->createSubClient([
            'name'        => $clientName,
            'email'       => $this->params['clientsdetails']['email'] ?? '',
            'password'    => $this->params['password'] ?: bin2hex(random_bytes(12)),
            'zone_limit'  => (int) ($this->params['configoption1'] ?? 5),
            'max_records' => (int) ($this->params['configoption2'] ?? 100),
            'status'      => 'active',
        ]);

        if (!$resp['ok']) return $resp['error'] ?: 'CreateSubClient failed.';

        $newId = (int) ($resp['data']['id'] ?? 0);
        if ($newId <= 0) return 'CreateSubClient succeeded but no id returned.';

        $this->setSubClientId($newId);

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
        $resp = $this->api->deleteSubClient($id);
        return $resp['ok'] ? 'success' : ($resp['error'] ?: 'Terminate failed.');
    }

    public function changePackage(): string
    {
        $id = $this->subClientId();
        if (!$id) return 'No sub-client id.';

        $resp = $this->api->patchSubClient($id, [
            'zone_limit'  => (int) ($this->params['configoption1'] ?? 5),
            'max_records' => (int) ($this->params['configoption2'] ?? 100),
        ]);
        return $resp['ok'] ? 'success' : ($resp['error'] ?: 'ChangePackage failed.');
    }

    public function testConnection(): string
    {
        $sum = $this->api->summary();
        return $sum['ok'] ? 'success' : ('Auth failed: ' . ($sum['error'] ?: ''));
    }

    public function openPortal(): string
    {
        $id = $this->subClientId();
        if (!$id) return 'No sub-client id.';
        $resp = $this->api->mintSubClientSsoToken($id);
        return $resp['ok'] ? 'success' : ('SSO mint failed: ' . ($resp['error'] ?: ''));
    }

    public function resyncStatus(): string
    {
        $id = $this->subClientId();
        if (!$id) return 'No sub-client id.';
        $resp = $this->api->subClientSummary($id);
        return $resp['ok'] ? 'success' : ($resp['error'] ?: '');
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

        $sub    = $cached['sub_client'] ?? [];
        $usage  = $cached['usage']      ?? [];
        $limits = $cached['limits']     ?? [];
        $status = $sub['status'] ?? 'unknown';
        $colour = $status === 'active' ? '#0a7' : ($status === 'suspended' ? '#c80' : '#888');

        $moduleVersion = defined('PANELDNS_RESELLER_MODULE_VERSION')
            ? PANELDNS_RESELLER_MODULE_VERSION : 'unknown';

        return [
            'Module version'         => '<span style="color:#6b7280;font-variant-numeric:tabular-nums;">paneldns-reseller v'
                . htmlspecialchars($moduleVersion, ENT_QUOTES, 'UTF-8') . '</span>',
            'PanelDNS Sub-client ID' => '<strong>' . htmlspecialchars((string) $id, ENT_QUOTES, 'UTF-8') . '</strong>',
            'PanelDNS Status'        => sprintf(
                '<span style="color:%s; font-weight:600; text-transform:capitalize;">%s</span>',
                $colour,
                htmlspecialchars($status, ENT_QUOTES, 'UTF-8')
            ),
            'Sub-client Email' => htmlspecialchars((string) ($sub['email'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'Zones used / limit' => sprintf(
                '%d / %s',
                (int) ($usage['zones'] ?? 0),
                (int) ($limits['zones'] ?? 0) > 0 ? (int) $limits['zones'] : '∞'
            ),
            'Records used / limit' => sprintf(
                '%d / %s',
                (int) ($usage['records'] ?? 0),
                (int) ($limits['records'] ?? 0) > 0 ? (int) $limits['records'] : '∞'
            ),
        ];
    }

    public static function clientArea(array $params): array
    {
        // T1.4: dispatch on customAction to either the embedded DNS
        // manager (zones / records / create / import / mutations) or
        // the legacy "overview" template with usage cards + SSO button.
        $action = $params['customAction'] ?? '';
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
            'paneldns_status' => 'unknown',
            'paneldns_usage'  => null,
            'paneldns_limits' => null,
            'paneldns_error'  => null,
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

        $vars['paneldns_status'] = $data['sub_client']['status'] ?? 'unknown';
        $vars['paneldns_usage']  = $data['usage'] ?? null;
        $vars['paneldns_limits'] = $data['limits'] ?? null;
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

        // T2.3: prefer per-product NS overrides (configoption4-7) when set;
        // otherwise fall back to the org's configured nameservers via
        // /api/v1/org/nameservers. Useful when the same reseller org sells
        // under multiple WHMCS brands and each brand shows a different NS.
        $overrideNs = array_values(array_filter([
            trim((string) ($this->params['configoption4'] ?? '')),
            trim((string) ($this->params['configoption5'] ?? '')),
            trim((string) ($this->params['configoption6'] ?? '')),
            trim((string) ($this->params['configoption7'] ?? '')),
        ], fn ($v) => $v !== ''));

        if (!empty($overrideNs)) {
            $context['nameservers'] = implode("\n", $overrideNs);
        } else {
            $ns = $this->api->nameservers();
            if ($ns['ok'] && !empty($ns['data']['nameservers'])) {
                $context['nameservers'] = implode("\n", $ns['data']['nameservers']);
            }
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
