<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/**
 * TERM-ZONES-01 (v1.9.6): Terminate (and the grace-period cron) deletes every zone of the
 * sub-client before the sub-client itself, and keeps the sub-client when a zone cannot be
 * deleted.
 *
 * PanelDNS's DELETE /api/v1/sub-clients/{id} deletes only the sub-client row; the zones
 * table's sub_client_id FK is nullOnDelete. Up to 1.9.5 a terminated customer's zones
 * therefore stayed live at the DNS provider as org-level zones, indefinitely.
 *
 * @covers \PanelDnsResellerService
 * @covers \PanelDnsResellerHooks
 */
final class TerminateZonesTest extends TestCase
{
    private const SUB = 42;

    private FakeApi $api;

    protected function setUp(): void
    {
        Capsule::reset();
        PanelDnsResellerApi::resetRateLimitState();
        $GLOBALS['__activity_log'] = [];
        $this->api = new FakeApi();
        $api = $this->api;
        PanelDnsResellerService::$apiFactory = fn () => $api;
        PanelDnsResellerHooks::$apiFactory = fn () => $api;
    }

    protected function tearDown(): void
    {
        Capsule::reset();
        PanelDnsResellerService::$apiFactory = null;
        PanelDnsResellerHooks::$apiFactory = null;
    }

    /** GET /api/v1/zones as PanelDNS answers it: 50 per page, filtered by sub_client_id unless $ignoreFilter. */
    private function zones(array $zones, bool $ignoreFilter = false, ?int $failPage = null): void
    {
        $this->api->on('GET', '/api/v1/zones', function (array $q) use ($zones, $ignoreFilter, $failPage) {
            $page = max(1, (int) ($q['page'] ?? 1));
            if ($page === $failPage) return FakeApi::json(503, ['message' => 'Service Unavailable']);
            $rows = $ignoreFilter ? $zones : array_values(array_filter($zones, fn ($z) => (int) $z['sub_client_id'] === (int) ($q['sub_client_id'] ?? 0)));
            return FakeApi::json(200, ['ok' => true, 'data' => array_slice($rows, ($page - 1) * 50, 50),
                'meta' => ['page' => $page, 'per_page' => 50, 'total' => count($rows)]]);
        });
        foreach ($zones as $z) {
            $this->api->on('DELETE', '/api/v1/zones/' . $z['id'], FakeApi::json(200, ['ok' => true, 'data' => null]));
        }
        $this->api->on('DELETE', '/api/v1/sub-clients/' . self::SUB, FakeApi::json(200, ['ok' => true]));
    }

    private static function zoneRows(int $count, int $subClientId = self::SUB, int $firstId = 1): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['id' => $firstId + $i, 'name' => 'z' . ($firstId + $i) . '.test', 'status' => 'active', 'sub_client_id' => $subClientId];
        }
        return $rows;
    }

    private function terminate(array $over = []): string
    {
        return PanelDnsResellerService::run('terminateAccount', $over + [
            'serviceid' => 500, 'userid' => 7, 'serverhostname' => 'paneldns.test', 'serversecure' => true,
            'configoption11' => '0', 'model' => (object) ['id' => 500, 'dedicatedip' => (string) self::SUB],
        ]);
    }

    /** Method + path of every write, in order. */
    private function writes(): array
    {
        return array_values(array_map(
            fn (array $c) => $c['method'] . ' ' . $c['path'],
            array_filter($this->api->calls, fn (array $c) => $c['method'] !== 'GET')
        ));
    }

    // ── TerminateAccount ───────────────────────────────────────────────────────

    public function test_terminate_deletes_every_zone_then_the_sub_client(): void
    {
        $this->zones(self::zoneRows(60)); // two pages

        $this->assertSame('success', $this->terminate());

        $writes = $this->writes();
        $this->assertCount(61, $writes);
        $this->assertSame('DELETE /api/v1/zones/1', $writes[0]);
        $this->assertSame('DELETE /api/v1/zones/60', $writes[59]);
        $this->assertSame('DELETE /api/v1/sub-clients/' . self::SUB, $writes[60], 'the sub-client goes last');
        $this->assertSame((string) self::SUB, $this->api->callsTo('GET', '/api/v1/zones')[0]['query']['sub_client_id']);
    }

    public function test_a_zone_that_cannot_be_deleted_keeps_the_sub_client(): void
    {
        $this->zones(self::zoneRows(3));
        $this->api->on('DELETE', '/api/v1/zones/2', FakeApi::json(422, ['ok' => false, 'error' => 'Provider error.', 'code' => 422]));

        $result = $this->terminate();

        $this->assertStringContainsString('sub-client #42 was not deleted', $result);
        $this->assertStringContainsString('z2.test (#2)', $result);
        $this->assertStringContainsString('Run Terminate again', $result);
        $this->assertSame([], $this->api->callsTo('DELETE', '/api/v1/sub-clients/' . self::SUB));
    }

    public function test_a_failed_zone_listing_deletes_nothing(): void
    {
        $this->zones(self::zoneRows(60), false, 2);

        $this->assertStringContainsString('listing its zones failed', $this->terminate());
        $this->assertSame([], $this->writes());
    }

    public function test_a_zone_already_gone_is_not_an_error(): void
    {
        $this->zones(self::zoneRows(2));
        $this->api->on('DELETE', '/api/v1/zones/1', FakeApi::json(404, ['ok' => false, 'error' => 'Not found.', 'code' => 404]));

        $this->assertSame('success', $this->terminate());
        $this->assertCount(1, $this->api->callsTo('DELETE', '/api/v1/sub-clients/' . self::SUB));
    }

    public function test_only_the_sub_clients_own_zones_are_deleted_even_if_the_filter_is_ignored(): void
    {
        // An older server that ignores ?sub_client_id= lists every zone of the org.
        $this->zones(array_merge(self::zoneRows(2), self::zoneRows(2, 77, 100), self::zoneRows(1, 0, 200)), true);

        $this->assertSame('success', $this->terminate());
        $this->assertSame(['DELETE /api/v1/zones/1', 'DELETE /api/v1/zones/2', 'DELETE /api/v1/sub-clients/' . self::SUB], $this->writes());
    }

    public function test_a_grace_period_terminate_keeps_the_zones_until_the_period_ends(): void
    {
        $this->zones(self::zoneRows(2));
        $this->api->on('PATCH', '/api/v1/sub-clients/' . self::SUB, FakeApi::json(200, ['ok' => true, 'data' => ['id' => self::SUB]]));
        Capsule::$handler = fn (FakeQuery $q, string $op) => $op === 'value' ? '' : null;

        $this->assertSame('success', $this->terminate(['configoption11' => '14']));
        $this->assertSame(['PATCH /api/v1/sub-clients/' . self::SUB], $this->writes());
    }

    // ── Grace-period cron ──────────────────────────────────────────────────────

    private function runGraceCron(): void
    {
        $row = (object) [
            'service_id' => 500, 'sub_client_id' => (string) self::SUB, 'notes' => "keep\n[paneldns-grace:2000-01-01]",
            'server_hostname' => 'paneldns.test', 'server_key' => 'k', 'server_secure' => 1, 'server_port' => 443,
        ];
        Capsule::$handler = fn (FakeQuery $q, string $op) => $op === 'get' ? [$row] : null;
        PanelDnsResellerHooks::processExpiredGracePeriods();
    }

    public function test_the_grace_cron_deletes_the_zones_then_the_sub_client(): void
    {
        $this->zones(self::zoneRows(3));

        $this->runGraceCron();

        $this->assertSame([
            'DELETE /api/v1/zones/1', 'DELETE /api/v1/zones/2', 'DELETE /api/v1/zones/3', 'DELETE /api/v1/sub-clients/' . self::SUB,
        ], $this->writes());
        $this->assertCount(1, Capsule::ops('update', 'tblhosting'), 'the grace marker is cleared');
        $this->assertStringContainsString('deleted sub-client 42 and 3 zone(s)', implode("\n", $GLOBALS['__activity_log']));
    }

    public function test_the_grace_cron_keeps_the_marker_when_a_zone_delete_fails(): void
    {
        $this->zones(self::zoneRows(3));
        $this->api->on('DELETE', '/api/v1/zones/3', FakeApi::json(503, ['message' => 'Service Unavailable']));

        $this->runGraceCron();

        $this->assertSame([], $this->api->callsTo('DELETE', '/api/v1/sub-clients/' . self::SUB));
        $this->assertSame([], Capsule::ops('update', 'tblhosting'), 'the marker stays so the next cron retries');
        $this->assertStringContainsString('grace delete postponed for sub-client 42', implode("\n", $GLOBALS['__activity_log']));
    }
}
