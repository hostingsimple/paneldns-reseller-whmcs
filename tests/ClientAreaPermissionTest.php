<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Database\Capsule;

/**
 * WHMCS-PERM-01 (v1.9.6): the in-template portal login (?customAction=sso) and every page
 * and form of the embedded DNS manager need the signed-in WHMCS user's "Perform Single
 * Sign-On" (productsso) permission on the client account. The owner has every permission.
 *
 * Up to 1.9.5 they ran for anyone who could open the product page, so a user the owner
 * had invited with view-only access got a full portal login and could delete zones.
 * WHMCS's own Login button (ServiceSingleSignOn) was already gated by WHMCS.
 *
 * @covers \PanelDnsResellerService
 */
final class ClientAreaPermissionTest extends TestCase
{
    private const CLIENT  = 7;
    private const OWNER   = 100;
    private const INVITED = 101;
    private const SUB     = 42;

    private FakeApi $api;

    protected function setUp(): void
    {
        Capsule::reset();
        \WHMCS\Cache\Store::clear();
        PanelDnsResellerApi::resetRateLimitState();
        CurrentUser::reset();
        $GLOBALS['__local_api'] = [];
        $GLOBALS['__activity_log'] = [];
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];

        $this->api = (new FakeApi())
            ->on('POST', '/api/v1/sub-clients/' . self::SUB . '/sso-token', FakeApi::json(200, ['ok' => true, 'data' => [
                'login_url' => 'https://paneldns.test/portal/sso/consume?token=x', 'expires_in' => 60,
            ]]))
            ->on('GET', '/api/v1/sub-clients/' . self::SUB . '/summary', FakeApi::json(200, ['ok' => true, 'data' => [
                'sub_client' => ['id' => self::SUB, 'status' => 'active'], 'usage' => ['zones' => 1, 'records' => 2], 'limits' => ['zones' => 5, 'records' => 100],
            ]]))
            ->on('GET', '/api/v1/zones', FakeApi::json(200, ['ok' => true, 'data' => [
                ['id' => 9, 'name' => 'example.com', 'status' => 'active', 'sub_client_id' => self::SUB],
            ], 'meta' => ['page' => 1, 'per_page' => 50, 'total' => 1]]))
            ->on('GET', '/api/v1/zones/9', FakeApi::json(200, ['ok' => true, 'data' => ['id' => 9, 'name' => 'example.com', 'sub_client_id' => self::SUB]]))
            ->on('GET', '/api/v1/zones/9/export', ['status' => 200, 'body' => "example.com. 3600 IN A 192.0.2.1\n", 'headers' => [], 'curl_error' => '', 'curl_errno' => 0, 'primary_ip' => '203.0.113.10'])
            ->on('DELETE', '/api/v1/zones/9', FakeApi::json(200, ['ok' => true, 'data' => null]))
            ->on('GET', '/api/v1/org/nameservers', FakeApi::json(200, ['ok' => true, 'data' => ['nameservers' => ['ns1.acme.test']]]));
        $api = $this->api;
        PanelDnsResellerService::$apiFactory = fn () => $api;
    }

    protected function tearDown(): void
    {
        Capsule::reset();
        \WHMCS\Cache\Store::clear();
        CurrentUser::reset();
        PanelDnsResellerService::$apiFactory = null;
        unset($GLOBALS['__local_api_handler'], $_SERVER['REQUEST_METHOD']);
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
    }

    private static function params(): array
    {
        return [
            'serviceid' => 500, 'userid' => self::CLIENT, 'status' => 'Active',
            'serverhostname' => 'paneldns.test', 'serversecure' => true,
            'model' => (object) ['id' => 500, 'dedicatedip' => (string) self::SUB],
        ];
    }

    /** The invited (non-owner) user holds $permissions on the client, as GetUserPermissions reports them. */
    private function signInInvitedUser($permissions): void
    {
        CurrentUser::signIn(self::INVITED, self::CLIENT, self::OWNER);
        $GLOBALS['__local_api_handler'] = function (string $command, array $vars) use ($permissions) {
            if ($command !== 'GetUserPermissions') return ['result' => 'success'];
            if ((int) $vars['user_id'] !== self::INVITED || (int) $vars['client_id'] !== self::CLIENT) {
                return ['result' => 'error', 'message' => 'User is not associated with client'];
            }
            return ['result' => 'success', 'permissions' => $permissions];
        };
    }

    private function open(string $action, array $post = []): array
    {
        $_REQUEST = ['customAction' => $action];
        if ($post !== []) {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SESSION['paneldns_csrf_500'] = 'tok';
            $_POST = $post + ['csrf' => 'tok'];
        } else {
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }
        return PanelDnsResellerService::clientArea(self::params());
    }

    private function assertRefused(array $out): void
    {
        $this->assertSame('templates/client/overview', $out['templatefile']);
        $this->assertSame(PanelDnsResellerService::MSG_NO_PERMISSION, $out['vars']['paneldns_notice']);
        $this->assertFalse($out['vars']['paneldns_can_manage']);
    }

    // ── A view-only user ───────────────────────────────────────────────────────

    public function test_a_view_only_user_gets_no_portal_login(): void
    {
        $this->signInInvitedUser(['products', 'invoices']);

        $out = $this->open('sso');

        $this->assertRefused($out);
        $this->assertSame([], $this->api->callsTo('POST', '/api/v1/sub-clients/' . self::SUB . '/sso-token'), 'no SSO token is minted');
    }

    public function test_a_view_only_user_cannot_delete_a_zone(): void
    {
        $this->signInInvitedUser(['products']);

        $out = $this->open('do-zone-delete', ['zone_id' => 9]);

        $this->assertRefused($out);
        $this->assertSame([], $this->api->callsTo('DELETE', '/api/v1/zones/9'));
    }

    /** @dataProvider embeddedActionProvider */
    public function test_a_view_only_user_reaches_no_embedded_page(string $action): void
    {
        $this->signInInvitedUser(['products']);

        $out = $this->open($action);

        $this->assertRefused($out);
        $this->assertSame([], $this->api->callsTo('GET', '/api/v1/zones/9/export'));
    }

    public static function embeddedActionProvider(): array
    {
        return array_combine(PanelDnsResellerService::EMBEDDED_ACTIONS, array_map(fn ($a) => [$a], PanelDnsResellerService::EMBEDDED_ACTIONS));
    }

    public function test_the_overview_still_shows_but_without_the_dns_buttons(): void
    {
        $this->signInInvitedUser(['products']);

        $out = $this->open('');

        $this->assertSame('templates/client/overview', $out['templatefile']);
        $this->assertFalse($out['vars']['paneldns_can_manage']);
        $this->assertNull($out['vars']['paneldns_notice']);
        $this->assertSame(['zones' => 1, 'records' => 2], $out['vars']['paneldns_usage']);

        $tpl = (string) file_get_contents(__DIR__ . '/../modules/servers/paneldnsreseller/templates/client/overview.tpl');
        $this->assertMatchesRegularExpression('/\{if \$paneldns_sub_client_id && !\$paneldns_can_manage\}.*?\{elseif \$paneldns_sub_client_id\}.*?customAction=sso/s', $tpl,
            'the Manage DNS / portal buttons render only when the user may manage DNS');
    }

    // ── Users who may manage DNS ───────────────────────────────────────────────

    public function test_the_account_owner_may_manage_without_a_permission_lookup(): void
    {
        CurrentUser::signIn(self::OWNER, self::CLIENT, self::OWNER);

        $out = $this->open('sso');

        $this->assertSame('templates/client/sso-redirect', $out['templatefile']);
        $this->assertSame([], $GLOBALS['__local_api'], 'owners hold every permission');
    }

    /** @dataProvider grantedProvider */
    public function test_a_user_with_the_sso_permission_may_manage($permissions): void
    {
        $this->signInInvitedUser($permissions);

        $out = $this->open('do-zone-delete', ['zone_id' => 9]);

        $this->assertSame('templates/client/zones-list', $out['templatefile']);
        $this->assertCount(1, $this->api->callsTo('DELETE', '/api/v1/zones/9'));
        $this->assertSame(['GetUserPermissions', ['user_id' => self::INVITED, 'client_id' => self::CLIENT]], $GLOBALS['__local_api'][0]);
    }

    public static function grantedProvider(): array
    {
        return [
            'list'          => [['products', 'productsso']],
            'comma string'  => ['profile,products,productsso,invoices'],
            'nested'        => [['permission' => ['products', 'productsso']]],
            'name => true'  => [['products' => true, 'productsso' => true]],
        ];
    }

    // ── Fail closed ────────────────────────────────────────────────────────────

    public function test_no_signed_in_user_is_refused(): void
    {
        $this->assertRefused($this->open('sso'));
    }

    public function test_a_user_signed_in_on_another_client_account_is_refused(): void
    {
        CurrentUser::signIn(self::OWNER, self::CLIENT + 1, self::OWNER);

        $this->assertRefused($this->open('sso'));
    }

    public function test_a_failed_permission_lookup_is_refused(): void
    {
        CurrentUser::signIn(self::INVITED, self::CLIENT, self::OWNER);
        $GLOBALS['__local_api_handler'] = fn () => ['result' => 'error', 'message' => 'Invalid user id'];

        $this->assertRefused($this->open('sso'));
    }

    public function test_an_unreadable_permission_answer_is_refused(): void
    {
        $this->signInInvitedUser(null);

        $this->assertRefused($this->open('sso'));
        $this->assertSame([], PanelDnsResellerService::permissionNames(null));
        $this->assertSame(['products'], PanelDnsResellerService::permissionNames(['products' => true, 'productsso' => false]));
    }
}
