<?php

use PHPUnit\Framework\TestCase;

/**
 * The embedded zone manager against canned PanelDNS responses: import field and size
 * cap (finding 3), zone-list paging (finding 7), and the API calls a page costs.
 *
 * Rendered flows use $_SESSION as a plain array: the manager only calls session_start()
 * while headers can still be sent, which is never the case under PHPUnit.
 *
 * @covers \PanelDnsEmbeddedDnsManager
 */
final class EmbeddedDnsManagerTest extends TestCase
{
    // ── Import payload ─────────────────────────────────────────────────────────

    public function test_import_payload_uses_the_zone_file_field(): void
    {
        $this->assertSame(['zone_file' => "@ 3600 IN A 192.0.2.1\n"], PanelDnsEmbeddedDnsManager::buildImportPayload("@ 3600 IN A 192.0.2.1\n"));
    }

    public function test_import_accepts_exactly_50_kb(): void
    {
        $text = str_repeat('a', PanelDnsEmbeddedDnsManager::IMPORT_MAX_BYTES);
        $this->assertSame(51200, PanelDnsEmbeddedDnsManager::IMPORT_MAX_BYTES, 'matches PanelDNS zone_file max:51200');
        $this->assertSame($text, PanelDnsEmbeddedDnsManager::buildImportPayload($text)['zone_file']);
    }

    public function test_import_rejects_one_byte_over_50_kb_with_a_clear_message(): void
    {
        try {
            PanelDnsEmbeddedDnsManager::buildImportPayload(str_repeat('a', PanelDnsEmbeddedDnsManager::IMPORT_MAX_BYTES + 1));
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('too large', $e->getMessage());
            $this->assertStringContainsString('50 KB', $e->getMessage());
        }
    }

    public function test_import_counts_bytes_not_characters(): void
    {
        // 25,601 two-byte characters = 51,202 bytes: over the cap even though it is
        // only 25,601 characters.
        $this->expectException(\InvalidArgumentException::class);
        PanelDnsEmbeddedDnsManager::buildImportPayload(str_repeat('é', 25601));
    }

    public function test_import_rejects_empty_text(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PanelDnsEmbeddedDnsManager::buildImportPayload("  \n ");
    }

    // ── Rendered flows ─────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        \WHMCS\Cache\Store::clear();
        PanelDnsResellerApi::resetRateLimitState();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        \WHMCS\Cache\Store::clear();
        unset($_SERVER['REQUEST_METHOD']);
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
    }

    private static function startSession(): void
    {
        // Sessions cannot start under PHPUnit (output already sent); $_SESSION is used
        // as a plain array, as the manager does in that case.
        $_SESSION = $_SESSION ?? [];
    }

    private static function zonePage(int $page, int $total, int $subClientId = 42): array
    {
        $rows = [];
        for ($i = ($page - 1) * 50 + 1; $i <= min($total, $page * 50); $i++) {
            $rows[] = ['id' => $i, 'name' => "z{$i}.test", 'status' => 'active', 'sub_client_id' => $subClientId];
        }
        return FakeApi::json(200, ['ok' => true, 'data' => $rows, 'meta' => ['page' => $page, 'per_page' => 50, 'total' => $total]]);
    }

    public function test_zone_list_shows_every_zone_not_just_the_first_50(): void
    {
        self::startSession();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $api = (new FakeApi())->on('GET', '/api/v1/zones', fn (array $q) => self::zonePage((int) ($q['page'] ?? 1), 73));
        $out = (new PanelDnsEmbeddedDnsManager($api, 42, ['serviceid' => 500]))->handle('zones');

        $this->assertSame('templates/client/zones-list', $out['templatefile']);
        $this->assertCount(73, $out['vars']['zones']);
        $this->assertSame(73, $out['vars']['zones_total']);
        $this->assertFalse($out['vars']['zones_truncated']);
        $this->assertSame('42', $api->calls[0]['query']['sub_client_id']);
    }

    public function test_import_posts_zone_file_and_returns_to_the_records_page(): void
    {
        self::startSession();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['paneldns_csrf_500'] = 'tok';
        $_POST = ['csrf' => 'tok', 'zone_id' => '9', 'zone_file' => "@ 3600 IN A 192.0.2.1\n"];
        $_GET  = [];

        $api = (new FakeApi())
            ->on('GET', '/api/v1/zones/9', FakeApi::json(200, ['ok' => true, 'data' => ['id' => 9, 'name' => 'example.com', 'sub_client_id' => 42]]))
            ->on('POST', '/api/v1/zones/9/import', FakeApi::json(200, ['ok' => true, 'data' => null]))
            ->on('GET', '/api/v1/zones/9/records', FakeApi::json(200, ['ok' => true, 'data' => [
                ['id' => 1, 'name' => '@', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600],
            ], 'meta' => ['page' => 1, 'per_page' => 500, 'total' => 1]]))
            ->on('GET', '/api/v1/zones/9/dnssec', FakeApi::json(422, ['ok' => false, 'error' => 'Zone has no provider.', 'code' => 422]))
            ->on('GET', '/api/v1/org/nameservers', FakeApi::json(200, ['ok' => true, 'data' => ['nameservers' => ['ns1.acme.test']]]));

        $out = (new PanelDnsEmbeddedDnsManager($api, 42, ['serviceid' => 500]))->handle('do-zone-import');

        $import = $api->callsTo('POST', '/api/v1/zones/9/import');
        $this->assertCount(1, $import);
        $this->assertSame(['zone_file' => "@ 3600 IN A 192.0.2.1\n"], $import[0]['body']);

        // REDIRECT-01: lands on the records page of the zone (its id was only in POST).
        $this->assertSame('templates/client/zone-records', $out['templatefile']);
        $this->assertSame('success', $out['vars']['flash']['type']);
        $this->assertStringContainsString('imported', $out['vars']['flash']['msg']);
        $this->assertStringNotContainsString('?', $out['vars']['flash']['msg'], 'no made-up record count');

        // The ownership lookup is memoised: one GET of the zone for import + re-render.
        $this->assertCount(1, $api->callsTo('GET', '/api/v1/zones/9'));
    }

    public function test_oversized_import_never_reaches_panel_dns(): void
    {
        self::startSession();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['paneldns_csrf_500'] = 'tok';
        $_POST = ['csrf' => 'tok', 'zone_id' => '9', 'zone_file' => str_repeat('x', 51201)];

        $api = (new FakeApi())
            ->on('GET', '/api/v1/zones/9', FakeApi::json(200, ['ok' => true, 'data' => ['id' => 9, 'name' => 'example.com', 'sub_client_id' => 42]]))
            ->on('GET', '/api/v1/zones', fn (array $q) => self::zonePage(1, 1));

        $out = (new PanelDnsEmbeddedDnsManager($api, 42, ['serviceid' => 500]))->handle('do-zone-import');

        $this->assertSame([], $api->callsTo('POST', '/api/v1/zones/9/import'));
        $this->assertSame('error', $out['vars']['flash']['type']);
        $this->assertStringContainsString('50 KB', $out['vars']['flash']['msg']);
    }

    public function test_record_update_does_not_refetch_the_record(): void
    {
        self::startSession();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['paneldns_csrf_500'] = 'tok';
        $_POST = ['csrf' => 'tok', 'zone_id' => '9', 'record_id' => '5', 'name' => 'www', 'type' => 'A', 'content' => '192.0.2.7', 'ttl' => '300'];

        $api = (new FakeApi())
            ->on('GET', '/api/v1/zones/9', FakeApi::json(200, ['ok' => true, 'data' => ['id' => 9, 'name' => 'example.com', 'sub_client_id' => 42]]))
            ->on('PATCH', '/api/v1/zones/9/records/5', FakeApi::json(200, ['ok' => true, 'data' => []]))
            ->on('GET', '/api/v1/zones/9/records', FakeApi::json(200, ['ok' => true, 'data' => [], 'meta' => ['page' => 1, 'per_page' => 500, 'total' => 0]]))
            ->on('GET', '/api/v1/zones/9/dnssec', FakeApi::json(422, ['ok' => false, 'error' => 'Zone has no provider.', 'code' => 422]))
            ->on('GET', '/api/v1/org/nameservers', FakeApi::json(200, ['ok' => true, 'data' => ['nameservers' => []]]));

        $out = (new PanelDnsEmbeddedDnsManager($api, 42, ['serviceid' => 500]))->handle('do-record-update');

        $this->assertSame('templates/client/zone-records', $out['templatefile']);
        $this->assertSame([], $api->callsTo('GET', '/api/v1/zones/9/records/5'), 'no separate ownership GET of the record');
        $this->assertCount(1, $api->callsTo('PATCH', '/api/v1/zones/9/records/5'));
        // zone (1) + PATCH (1) + records (1) + dnssec (1) + nameservers (1) = 5, was 7.
        $this->assertCount(5, $api->calls);
    }

    public function test_mutations_require_post(): void
    {
        self::startSession();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = ['zone_id' => '9'];

        $api = (new FakeApi())->on('GET', '/api/v1/zones', fn (array $q) => self::zonePage(1, 0));
        $out = (new PanelDnsEmbeddedDnsManager($api, 42, ['serviceid' => 500]))->handle('do-zone-delete');

        $this->assertSame('templates/client/zones-list', $out['templatefile']);
        $this->assertSame([], $api->callsTo('DELETE', '/api/v1/zones/9'));
    }
}
