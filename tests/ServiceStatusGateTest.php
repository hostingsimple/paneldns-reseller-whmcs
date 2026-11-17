<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Database\Capsule;

/**
 * SVC-STATUS-01 (v1.9.6): the in-template portal login and the embedded DNS manager work
 * only for an Active service. A Suspended, Pending or Terminated (grace period) service
 * gets the read-only overview with a notice.
 *
 * Up to 1.9.5 clientArea() never looked at the service status, so whether a suspended
 * service could still edit DNS depended entirely on WHMCS's page rendering. PanelDNS
 * refuses writes through the reseller token only for a suspended zone, not a suspended
 * sub-client.
 *
 * @covers \PanelDnsResellerService
 */
final class ServiceStatusGateTest extends TestCase
{
    private const SUB = 42;

    private FakeApi $api;

    protected function setUp(): void
    {
        Capsule::reset();
        \WHMCS\Cache\Store::clear();
        PanelDnsResellerApi::resetRateLimitState();
        CurrentUser::signIn(100, 7, 100); // the account owner
        $GLOBALS['__local_api'] = [];
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];

        $this->api = (new FakeApi())
            ->on('POST', '/api/v1/sub-clients/' . self::SUB . '/sso-token', FakeApi::json(200, ['ok' => true, 'data' => [
                'login_url' => 'https://paneldns.test/portal/sso/consume?token=x', 'expires_in' => 60,
            ]]))
            ->on('GET', '/api/v1/sub-clients/' . self::SUB . '/summary', FakeApi::json(200, ['ok' => true, 'data' => [
                'sub_client' => ['id' => self::SUB, 'status' => 'suspended'], 'usage' => ['zones' => 1, 'records' => 2], 'limits' => ['zones' => 5, 'records' => 100],
            ]]))
            ->on('GET', '/api/v1/zones', FakeApi::json(200, ['ok' => true, 'data' => [
                ['id' => 9, 'name' => 'example.com', 'status' => 'active', 'sub_client_id' => self::SUB],
            ], 'meta' => ['page' => 1, 'per_page' => 50, 'total' => 1]]))
            ->on('GET', '/api/v1/zones/9', FakeApi::json(200, ['ok' => true, 'data' => ['id' => 9, 'name' => 'example.com', 'sub_client_id' => self::SUB]]))
            ->on('POST', '/api/v1/zones/9/records', FakeApi::json(201, ['ok' => true, 'data' => ['id' => 1]]))
            ->on('DELETE', '/api/v1/zones/9', FakeApi::json(200, ['ok' => true, 'data' => null]));
        $api = $this->api;
        PanelDnsResellerService::$apiFactory = fn () => $api;
    }

    protected function tearDown(): void
    {
        Capsule::reset();
        \WHMCS\Cache\Store::clear();
        CurrentUser::reset();
        PanelDnsResellerService::$apiFactory = null;
        unset($_SERVER['REQUEST_METHOD']);
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
    }

    private function open(?string $status, string $action, array $post = []): array
    {
        $params = [
            'serviceid' => 500, 'userid' => 7, 'serverhostname' => 'paneldns.test', 'serversecure' => true,
            'model' => (object) ['id' => 500, 'dedicatedip' => (string) self::SUB],
        ];
        if ($status !== null) {
            $params['status'] = $status;
        }
        $_REQUEST = ['customAction' => $action];
        if ($post !== []) {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SESSION['paneldns_csrf_500'] = 'tok';
            $_POST = $post + ['csrf' => 'tok'];
        } else {
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }
        return PanelDnsResellerService::clientArea($params);
    }

    /** @dataProvider inactiveProvider */
    public function test_an_inactive_service_gets_no_portal_login(?string $status): void
    {
        $out = $this->open($status, 'sso');

        $this->assertSame('templates/client/overview', $out['templatefile']);
        $this->assertStringContainsString('DNS management is not available while this service is', (string) $out['vars']['paneldns_notice']);
        $this->assertFalse($out['vars']['paneldns_can_manage']);
        $this->assertSame([], $this->api->callsTo('POST', '/api/v1/sub-clients/' . self::SUB . '/sso-token'));
    }

    /** @dataProvider inactiveProvider */
    public function test_an_inactive_service_cannot_change_dns(?string $status): void
    {
        $this->open($status, 'do-zone-delete', ['zone_id' => 9]);
        $this->open($status, 'do-record-create', ['zone_id' => 9, 'type' => 'A', 'name' => 'www', 'content' => '192.0.2.1']);

        $this->assertSame([], $this->api->callsTo('DELETE', '/api/v1/zones/9'));
        $this->assertSame([], $this->api->callsTo('POST', '/api/v1/zones/9/records'));
    }

    public static function inactiveProvider(): array
    {
        return [
            'suspended'          => ['Suspended'],
            'pending'            => ['Pending'],
            'terminated (grace)' => ['Terminated'],
            'no status'          => [null],
        ];
    }

    public function test_the_overview_still_renders_for_a_suspended_service(): void
    {
        $out = $this->open('Suspended', '');

        $this->assertSame('templates/client/overview', $out['templatefile']);
        $this->assertSame('DNS management is not available while this service is Suspended.', $out['vars']['paneldns_manage_reason']);
        $this->assertSame(['zones' => 1, 'records' => 2], $out['vars']['paneldns_usage']);
        $this->assertSame([], $GLOBALS['__local_api'], 'no permission lookup is needed for an inactive service');
    }

    public function test_an_active_service_still_works(): void
    {
        $out = $this->open('Active', 'do-zone-delete', ['zone_id' => 9]);

        $this->assertSame('templates/client/zones-list', $out['templatefile']);
        $this->assertCount(1, $this->api->callsTo('DELETE', '/api/v1/zones/9'));
    }
}
