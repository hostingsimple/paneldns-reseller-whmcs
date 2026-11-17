<?php

use PHPUnit\Framework\TestCase;

/**
 * ERR-LEAK-01 (v1.9.6): a client never sees PanelDNS's error text for a request that got
 * no answer or a 5xx; they get one generic line and the detail goes to the module log.
 * PanelDNS's own 4xx wording (validation, quota, rate limit) is still shown.
 *
 * Up to 1.9.5 the embedded DNS manager flashed PanelDNS's `error` verbatim. Zone export
 * and import answer 502 with the DNS provider's exception message (BIND over SSH,
 * PowerDNS…), which can name internal hosts, paths and provider responses.
 *
 * @covers \PanelDnsEmbeddedDnsManager
 * @covers \PanelDnsResellerService
 */
final class ClientErrorMessageTest extends TestCase
{
    private const SUB = 42;
    private const LEAK = 'ssh ns1.internal.example:22 failed: rndc: connect failed: 10.20.0.5#953 /var/named/zones';

    protected function setUp(): void
    {
        \WHMCS\Cache\Store::clear();
        PanelDnsResellerApi::resetRateLimitState();
        $GLOBALS['__module_log'] = [];
        $_SESSION = ['paneldns_csrf_500' => 'tok'];
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        \WHMCS\Cache\Store::clear();
        PanelDnsResellerService::$apiFactory = null;
        unset($_SERVER['REQUEST_METHOD']);
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
    }

    private static function api(): FakeApi
    {
        return (new FakeApi())
            ->on('GET', '/api/v1/zones/9', FakeApi::json(200, ['ok' => true, 'data' => ['id' => 9, 'name' => 'example.com', 'sub_client_id' => self::SUB]]))
            ->on('GET', '/api/v1/zones/9/records', FakeApi::json(200, ['ok' => true, 'data' => [], 'meta' => ['page' => 1, 'per_page' => 500, 'total' => 0]]))
            ->on('GET', '/api/v1/zones', FakeApi::json(200, ['ok' => true, 'data' => [], 'meta' => ['page' => 1, 'per_page' => 50, 'total' => 0]]))
            ->on('GET', '/api/v1/org/nameservers', FakeApi::json(200, ['ok' => true, 'data' => ['nameservers' => []]]));
    }

    private function post(FakeApi $api, string $action, array $post): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $post + ['csrf' => 'tok'];
        return (new PanelDnsEmbeddedDnsManager($api, self::SUB, ['serviceid' => 500, 'userid' => 7]))->handle($action);
    }

    private function assertGeneric(?array $flash): void
    {
        $msg = (string) ($flash['msg'] ?? '');
        $this->assertStringContainsString(PanelDnsEmbeddedDnsManager::MSG_SERVER_ERROR, $msg);
        $this->assertStringNotContainsString('internal.example', $msg);
        $this->assertStringNotContainsString('10.20.0.5', $msg);
        $this->assertStringNotContainsString('/var/named', $msg);
        $logged = implode("\n", array_map(
            fn ($c) => is_string($c[3] ?? null) ? $c[3] : (string) json_encode($c[3] ?? null, JSON_UNESCAPED_SLASHES),
            $GLOBALS['__module_log']
        ));
        $this->assertStringContainsString('ns1.internal.example', $logged, 'the detail is kept for the admin');
    }

    public function test_a_failed_import_shows_a_generic_line(): void
    {
        $api = self::api()->on('POST', '/api/v1/zones/9/import', FakeApi::json(502, ['ok' => false, 'error' => self::LEAK, 'code' => 502]));

        $out = $this->post($api, 'do-zone-import', ['zone_id' => 9, 'zone_file' => "@ 3600 IN A 192.0.2.1\n"]);

        $this->assertGeneric($out['vars']['flash']);
    }

    public function test_a_failed_export_shows_a_generic_line(): void
    {
        $api = self::api()->on('GET', '/api/v1/zones/9/export', FakeApi::json(502, ['ok' => false, 'error' => self::LEAK, 'code' => 502]));
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['zone' => 9];

        $out = (new PanelDnsEmbeddedDnsManager($api, self::SUB, ['serviceid' => 500]))->handle('zone-export');

        $this->assertGeneric($out['vars']['flash']);
    }

    /** @dataProvider serverFailureProvider */
    public function test_a_server_failure_on_a_record_write_shows_a_generic_line(array $response): void
    {
        $api = self::api()->on('POST', '/api/v1/zones/9/records', $response);

        $out = $this->post($api, 'do-record-create', ['zone_id' => 9, 'type' => 'A', 'name' => 'www', 'content' => '192.0.2.1']);

        $this->assertGeneric($out['vars']['flash']);
    }

    public static function serverFailureProvider(): array
    {
        return [
            'HTTP 500'  => [FakeApi::json(500, ['message' => self::LEAK])],
            'HTTP 503'  => [FakeApi::json(503, ['ok' => false, 'error' => self::LEAK])],
            'no answer' => [['status' => 0, 'body' => null, 'headers' => [], 'curl_error' => self::LEAK, 'curl_errno' => 7, 'primary_ip' => '']],
        ];
    }

    public function test_a_failed_zone_list_shows_a_generic_line(): void
    {
        $api = (new FakeApi())->on('GET', '/api/v1/zones', FakeApi::json(503, ['message' => self::LEAK]));
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $out = (new PanelDnsEmbeddedDnsManager($api, self::SUB, ['serviceid' => 500]))->handle('zones');

        $this->assertSame(PanelDnsEmbeddedDnsManager::MSG_SERVER_ERROR, $out['vars']['error']);
    }

    public function test_panel_dns_validation_and_quota_messages_are_still_shown(): void
    {
        $api = self::api()->on('POST', '/api/v1/zones/9/records', FakeApi::json(422, ['ok' => false, 'error' => 'Sub-client record limit reached.', 'code' => 422]));

        $out = $this->post($api, 'do-record-create', ['zone_id' => 9, 'type' => 'A', 'name' => 'www', 'content' => '192.0.2.1']);

        $this->assertSame('Add record failed: Sub-client record limit reached.', $out['vars']['flash']['msg']);
    }

    public function test_a_rate_limit_is_still_explained(): void
    {
        $resp = ['ok' => false, 'status' => 429, 'error' => 'PanelDNS rate limit reached (retry in 30 seconds).'];
        $this->assertSame('PanelDNS rate limit reached (retry in 30 seconds).', PanelDnsEmbeddedDnsManager::clientError($resp, 'x'));
    }

    public function test_the_whmcs_login_button_error_is_generic_on_a_server_failure(): void
    {
        $api = (new FakeApi())->on('POST', '/api/v1/sub-clients/' . self::SUB . '/sso-token', FakeApi::json(502, ['ok' => false, 'error' => self::LEAK]));
        PanelDnsResellerService::$apiFactory = fn () => $api;

        $r = PanelDnsResellerService::clientSso([
            'serviceid' => 500, 'serverhostname' => 'paneldns.test', 'serversecure' => true,
            'model' => (object) ['dedicatedip' => (string) self::SUB],
        ]);

        $this->assertFalse($r['success']);
        $this->assertSame(PanelDnsEmbeddedDnsManager::MSG_SERVER_ERROR, $r['errorMsg']);
    }
}
