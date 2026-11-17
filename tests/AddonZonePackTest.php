<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

require_once __DIR__ . '/../modules/servers/paneldnsreseller/paneldnsreseller.php'; // paneldnsreseller_ChangePackage

/**
 * ADDON-ZONES-01 (v1.9.6): only "PanelDNS +N Zones" addons grant zones, and the zone
 * limit is recomputed (product limit + active zone packs), never adjusted.
 *
 * Up to 1.9.5 every addon attached to the DNS service added the number in its name, or 5
 * when there was none, so any cheap or free addon granted 5 zones each time it was
 * ordered. AddonUnsuspended was not hooked (suspend + unsuspend lost the zones), suspend +
 * terminate subtracted twice, and ChangePackage reset the limit and wiped every grant.
 *
 * Addon state changes are driven through the handlers hooks.php registers; WHMCS's
 * ModuleChangePackage is answered by calling the module's own ChangePackage.
 *
 * @covers \PanelDnsResellerHooks
 * @covers \PanelDnsResellerService
 */
final class AddonZonePackTest extends TestCase
{
    private const SERVICE = 500;
    private const SUB     = 42;

    private FakeApi $api;
    /** Upstream sub-client state, changed by PATCH. */
    private array $subClient = ['id' => self::SUB, 'zone_limit' => 5, 'max_records' => 100, 'status' => 'active'];
    /** tbladdons: id => name */
    private array $addons = [];
    /** tblhostingaddons rows of the service: id => [addonid, status] */
    private array $serviceAddons = [];
    private string $baseZoneLimit = '5';
    private bool $ourService = true;

    protected function setUp(): void
    {
        Capsule::reset();
        \WHMCS\Cache\Store::clear();
        PanelDnsResellerApi::resetRateLimitState();
        $GLOBALS['__activity_log'] = [];
        $GLOBALS['__local_api'] = [];

        $this->api = (new FakeApi())
            ->on('GET', '/api/v1/sub-clients/' . self::SUB, fn () => FakeApi::json(200, ['ok' => true, 'data' => $this->subClient]))
            ->on('PATCH', '/api/v1/sub-clients/' . self::SUB, function (array $q, ?array $body) {
                $this->subClient = array_merge($this->subClient, (array) $body);
                return FakeApi::json(200, ['ok' => true, 'data' => $this->subClient]);
            });
        $api = $this->api;
        PanelDnsResellerHooks::$apiFactory  = fn () => $api;
        PanelDnsResellerService::$apiFactory = fn () => $api;

        Capsule::$handler = function (FakeQuery $q, string $op) {
            switch ($q->baseTable()) {
                case 'tblhosting':
                    return $op === 'first' && $this->ourService ? (object) [
                        'sub_client_id' => (string) self::SUB, 'server_hostname' => 'paneldns.test',
                        'server_key' => 'k', 'server_secure' => 1, 'server_port' => 443,
                    ] : null;
                case 'tbladdons':
                    return $op === 'value' ? ($this->addons[(int) $q->whereValue('id')] ?? null) : null;
                case 'tblhostingaddons':
                    if ($op !== 'get') return null;
                    $rows = [];
                    foreach ($this->serviceAddons as $id => [$addonId, $status]) {
                        $rows[] = (object) ['id' => $id, 'status' => $status, 'name' => $this->addons[$addonId] ?? ''];
                    }
                    return $rows;
            }
            return null;
        };

        // WHMCS's ModuleChangePackage runs the module's ChangePackage for the service.
        $GLOBALS['__local_api_handler'] = function (string $command, array $vars) {
            if ($command !== 'ModuleChangePackage') return ['result' => 'success'];
            $r = paneldnsreseller_ChangePackage($this->params((int) $vars['serviceid']));
            return $r === 'success' ? ['result' => 'success'] : ['result' => 'error', 'message' => $r];
        };
    }

    protected function tearDown(): void
    {
        Capsule::reset();
        \WHMCS\Cache\Store::clear();
        PanelDnsResellerHooks::$apiFactory = null;
        PanelDnsResellerService::$apiFactory = null;
        PanelDnsResellerHooks::$addonStateOverride = [];
        unset($GLOBALS['__local_api_handler']);
    }

    private function params(int $serviceId = self::SERVICE): array
    {
        return [
            'serviceid' => $serviceId, 'userid' => 7, 'serverhostname' => 'paneldns.test', 'serversecure' => true,
            'configoption1' => $this->baseZoneLimit, 'configoption2' => '100',
            'model' => (object) ['id' => $serviceId, 'dedicatedip' => (string) self::SUB],
        ];
    }

    /** hooks.php's registrations, loaded once through the add_hook() stub in bootstrap.php. */
    private static function hooks(): array
    {
        require_once __DIR__ . '/../modules/servers/paneldnsreseller/hooks.php';
        return $GLOBALS['__hooks'] ?? [];
    }

    /** WHMCS runs $point for addon row $id; WHMCS has (or has not yet) written $statusNow. */
    private function fire(string $point, int $id, ?string $statusNow = null): void
    {
        if ($statusNow !== null) {
            $this->serviceAddons[$id][1] = $statusNow;
        }
        $vars = ['id' => $id, 'userid' => 7, 'serviceid' => self::SERVICE, 'addonid' => $this->serviceAddons[$id][0]];
        foreach (self::hooks()[$point] ?? [] as $fn) {
            $fn($vars);
        }
    }

    private function zoneLimitPatches(): array
    {
        return array_values(array_map(
            fn (array $c) => $c['body']['zone_limit'],
            array_filter($this->api->callsTo('PATCH', '/api/v1/sub-clients/' . self::SUB), fn (array $c) => isset($c['body']['zone_limit']))
        ));
    }

    // ── Which addons count ─────────────────────────────────────────────────────

    /** @dataProvider addonNameProvider */
    public function test_only_panel_dns_zone_pack_names_grant_zones(string $name, int $zones): void
    {
        $this->assertSame($zones, PanelDnsResellerHooks::zonePackSize($name), $name);
    }

    public static function addonNameProvider(): array
    {
        return [
            'standard'              => ['PanelDNS +10 Zones', 10],
            'singular'              => ['PanelDNS +1 Zone', 1],
            'case and spacing'      => ['paneldns+ 25  zones', 25],
            'unrelated addon'       => ['Priority Support', 0],
            'free addon'            => ['Free SSL Certificate', 0],
            'number, no PanelDNS'   => ['Extra 25 Zones', 0],
            'zone word, no pattern' => ['DNS Zone Pack (+5)', 0],
            'trailing text'         => ['PanelDNS +10 Zones Bundle', 0],
            'leading text'          => ['Cheap PanelDNS +10 Zones', 0],
            'no number'             => ['PanelDNS Zone Pack', 0],
        ];
    }

    public function test_an_unrelated_addon_changes_nothing(): void
    {
        $this->addons = [3 => 'Priority Support'];
        $this->serviceAddons = [70 => [3, 'Pending']];

        $this->fire('AddonActivated', 70, 'Active');

        $this->assertSame([], $this->api->calls, 'an addon that is not a zone pack must not touch the zone limit');
        $this->assertSame([], array_filter($GLOBALS['__local_api'], fn ($c) => $c[0] === 'ModuleChangePackage'));
        $this->assertSame(5, $this->subClient['zone_limit']);
    }

    public function test_an_addon_on_another_modules_service_changes_nothing(): void
    {
        $this->ourService = false;
        $this->addons = [4 => 'PanelDNS +10 Zones'];
        $this->serviceAddons = [70 => [4, 'Pending']];

        $this->fire('AddonActivated', 70, 'Active');

        $this->assertSame([], $this->api->calls);
        $this->assertSame([], $GLOBALS['__local_api']);
    }

    // ── The limit is recomputed, not adjusted ──────────────────────────────────

    public function test_the_limit_follows_a_zone_pack_through_its_whole_life(): void
    {
        $this->addons = [4 => 'PanelDNS +10 Zones'];
        $this->serviceAddons = [70 => [4, 'Pending']];

        // WHMCS may run each hook before or after it writes the addon's new status; the
        // count must not depend on it, so the status here deliberately lags behind.
        $this->fire('AddonActivated', 70);            // still Pending in tblhostingaddons
        $this->assertSame(15, $this->subClient['zone_limit']);

        $this->fire('AddonSuspended', 70, 'Active');  // not yet written as Suspended
        $this->assertSame(5, $this->subClient['zone_limit']);

        $this->fire('AddonUnsuspended', 70, 'Suspended');
        $this->assertSame(15, $this->subClient['zone_limit'], 'suspend then unsuspend restores the zones');

        $this->fire('AddonTerminated', 70, 'Active');
        $this->assertSame(5, $this->subClient['zone_limit']);

        $this->fire('AddonCancelled', 70, 'Terminated');
        $this->assertSame(5, $this->subClient['zone_limit'], 'terminate then cancel does not subtract twice');

        $this->assertSame([15, 5, 15, 5, 5], $this->zoneLimitPatches());
        $this->assertSame([], PanelDnsResellerHooks::$addonStateOverride, 'the override is cleared after each hook');
    }

    public function test_repeating_an_activation_grants_the_pack_once(): void
    {
        $this->addons = [4 => 'PanelDNS +10 Zones'];
        $this->serviceAddons = [70 => [4, 'Active']];

        $this->fire('AddonActivated', 70);
        $this->fire('AddonActivated', 70);
        $this->fire('AddonActivated', 70);

        $this->assertSame(15, $this->subClient['zone_limit']);
    }

    public function test_a_package_change_keeps_the_active_zone_packs(): void
    {
        $this->addons = [4 => 'PanelDNS +10 Zones', 5 => 'PanelDNS +5 Zones', 6 => 'Priority Support'];
        $this->serviceAddons = [70 => [4, 'Active'], 71 => [5, 'Suspended'], 72 => [6, 'Active']];

        $this->assertSame('success', paneldnsreseller_ChangePackage($this->params()));

        $this->assertSame([15], $this->zoneLimitPatches(), 'product 5 + the one active zone pack');
    }

    public function test_inherit_the_org_plan_stays_uncapped_with_a_zone_pack(): void
    {
        $this->baseZoneLimit = '0';
        $this->addons = [4 => 'PanelDNS +10 Zones'];
        $this->serviceAddons = [70 => [4, 'Active']];

        $this->assertSame('success', paneldnsreseller_ChangePackage($this->params()));

        $this->assertSame([0], $this->zoneLimitPatches(), '0 = no per-sub-client cap; a pack must not impose one');
    }

    public function test_every_addon_state_hook_is_registered(): void
    {
        $hooks = self::hooks();
        foreach (['AddonActivated', 'AddonUnsuspended', 'AddonSuspended', 'AddonTerminated', 'AddonCancelled'] as $point) {
            $this->assertArrayHasKey($point, $hooks, $point);
        }
    }
}
