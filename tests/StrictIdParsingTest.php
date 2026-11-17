<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/**
 * ID-STRICT-01 (v1.9.6, ported from paneldns-whmcs 1.1.4 99bf546): every hook path reads
 * the sub-client id from Dedicated IP strictly. Dedicated IP is WHMCS's IP-address field,
 * and up to 1.9.5 the hooks cast it with (int), so '203.0.113.5' was sub-client 203:
 * another customer's. The grace cron would delete it (and, since TERM-ZONES-01, its
 * zones), ClientEdit would rename it, and domain auto-create would add a zone under it.
 * The drift sync's own check is in DriftSyncTest; Service::subClientId() was already strict.
 *
 * @covers \PanelDnsResellerHooks
 */
final class StrictIdParsingTest extends TestCase
{
    private const NOT_AN_ID = '203.0.113.5';

    private FakeApi $api;

    protected function setUp(): void
    {
        Capsule::reset();
        PanelDnsResellerApi::resetRateLimitState();
        $GLOBALS['__activity_log'] = [];
        $GLOBALS['__local_api'] = [];
        $this->api = (new FakeApi())
            ->on('GET', '/api/v1/zones', FakeApi::json(200, ['ok' => true, 'data' => [
                ['id' => 1, 'name' => 'victim.test', 'sub_client_id' => 203],
            ], 'meta' => ['page' => 1, 'per_page' => 50, 'total' => 1]]))
            ->on('DELETE', '/api/v1/zones/1', FakeApi::json(200, ['ok' => true, 'data' => null]))
            ->on('DELETE', '/api/v1/sub-clients/203', FakeApi::json(200, ['ok' => true]))
            ->on('PATCH', '/api/v1/sub-clients/203', FakeApi::json(200, ['ok' => true, 'data' => ['id' => 203]]))
            ->on('POST', '/api/v1/zones', FakeApi::json(201, ['ok' => true, 'data' => ['id' => 2]]));
        $api = $this->api;
        PanelDnsResellerHooks::$apiFactory = fn () => $api;
    }

    protected function tearDown(): void
    {
        Capsule::reset();
        PanelDnsResellerHooks::$apiFactory = null;
    }

    private static function row(array $over = []): object
    {
        return (object) ($over + [
            'service_id' => 500, 'order_id' => 0, 'sub_client_id' => self::NOT_AN_ID, 'notes' => "keep\n[paneldns-grace:2000-01-01]",
            'server_hostname' => 'paneldns.test', 'server_key' => 'k', 'server_secure' => 1, 'server_port' => 443,
            'auto_create' => 'on', 'auto_delete' => 'on',
        ]);
    }

    private function writes(): array
    {
        return array_values(array_filter($this->api->calls, fn (array $c) => $c['method'] !== 'GET'));
    }

    public function test_the_grace_cron_deletes_nothing_for_an_ip_address_and_keeps_the_marker(): void
    {
        Capsule::$handler = fn (FakeQuery $q, string $op) => $op === 'get' ? [self::row()] : null;

        PanelDnsResellerHooks::processExpiredGracePeriods();

        $this->assertSame([], $this->api->calls, 'sub-client 203 and its zones are left alone');
        $this->assertSame([], Capsule::ops('update', 'tblhosting'), 'the marker is kept for an admin');
        $this->assertStringContainsString('its Dedicated IP is not a sub-client id', implode("\n", $GLOBALS['__activity_log']));
    }

    public function test_the_grace_cron_still_clears_the_marker_of_an_unlinked_service(): void
    {
        Capsule::$handler = fn (FakeQuery $q, string $op) => $op === 'get' ? [self::row(['sub_client_id' => ''])] : null;

        PanelDnsResellerHooks::processExpiredGracePeriods();

        $this->assertSame([], $this->api->calls);
        $this->assertCount(1, Capsule::ops('update', 'tblhosting'));
    }

    public function test_client_edit_never_renames_sub_client_203(): void
    {
        Capsule::$handler = fn (FakeQuery $q, string $op) => $op === 'get' ? [self::row()] : null;

        PanelDnsResellerHooks::onClientEdit(7, 'Jane', 'Doe');

        $this->assertSame([], $this->writes());
    }

    public function test_domain_auto_create_never_files_a_zone_under_sub_client_203(): void
    {
        $domain = (object) ['id' => 3, 'userid' => 7, 'domain' => 'example.com', 'status' => 'Active', 'orderid' => 0];
        Capsule::$handler = function (FakeQuery $q, string $op) use ($domain) {
            if ($q->baseTable() === 'tblhosting') return $op === 'get' ? [self::row()] : null;
            if ($q->baseTable() === 'tbldomains') return $op === 'get' ? [$domain] : null;
            return null;
        };

        PanelDnsResellerHooks::onDomainEvent(7, 'example.com', 'create');

        $this->assertSame([], $this->writes());
    }

    public function test_an_addon_change_does_nothing_for_an_ip_address(): void
    {
        Capsule::$handler = fn (FakeQuery $q, string $op) => match ($q->baseTable()) {
            'tblhosting' => $op === 'first' ? self::row() : null,
            'tbladdons'  => $op === 'value' ? 'PanelDNS +10 Zones' : null,
            default      => null,
        };

        PanelDnsResellerHooks::onAddonChange(500, 4, 70, true);

        $this->assertSame([], $GLOBALS['__local_api']);
        $this->assertSame([], $this->writes());
    }

    public function test_the_hooks_use_the_drift_sync_rule(): void
    {
        foreach (['42' => 42, ' 42 ' => 42, self::NOT_AN_ID => null, '0' => null, '' => null, '1e3' => null] as $v => $expected) {
            $this->assertSame($expected, PanelDnsResellerHooks::parseId((string) $v), var_export($v, true));
        }
        $this->assertSame(PanelDnsResellerDriftSync::parseId('99999999999999999999'), PanelDnsResellerHooks::parseId('99999999999999999999'));
    }
}
