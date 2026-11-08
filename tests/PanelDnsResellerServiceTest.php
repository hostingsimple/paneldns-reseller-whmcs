<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/**
 * CreateAccount paths, the duplicate-email policy, ListAccounts paging and client-area
 * routing, against canned PanelDNS responses.
 *
 * @covers \PanelDnsResellerService
 */
final class PanelDnsResellerServiceTest extends TestCase
{
    private FakeApi $api;

    protected function setUp(): void
    {
        Capsule::reset();
        \WHMCS\Cache\Store::clear();
        PanelDnsResellerApi::resetRateLimitState();
        $GLOBALS['__local_api'] = [];

        $this->api = new FakeApi();
        $api = $this->api;
        PanelDnsResellerService::$apiFactory = fn () => $api;

        // A licensed org by default.
        $this->api
            ->on('GET', '/api/v1/licence-status', FakeApi::json(200, ['ok' => true, 'data' => [
                'format' => 1, 'sub_status' => 'active', 'modules_unlocked' => ['whmcs-reseller'], 'expires_at' => null,
            ]]))
            ->on('GET', '/api/v1/legal-version', FakeApi::json(200, ['ok' => true, 'version' => '2026-04-01']))
            ->on('GET', '/api/v1/summary', FakeApi::json(200, ['ok' => true, 'data' => [
                'org' => ['id' => 3, 'slug' => 'acme'], 'links' => ['portal' => 'https://paneldns.test/portal/acme'],
            ]]))
            ->on('GET', '/api/v1/org/nameservers', FakeApi::json(200, ['ok' => true, 'data' => ['nameservers' => ['ns1.acme.test', 'ns2.acme.test']]]))
            ->on('POST', '/api/v1/sub-clients/77/sso-token', FakeApi::json(200, ['ok' => true, 'data' => ['login_url' => 'https://paneldns.test/portal/sso/consume?token=x', 'expires_in' => 60]]));
    }

    protected function tearDown(): void
    {
        PanelDnsResellerService::$apiFactory = null;
        Capsule::reset();
        \WHMCS\Cache\Store::clear();
        $_REQUEST = [];
    }

    private function params(array $over = []): array
    {
        return array_replace_recursive([
            'serviceid'      => 500,
            'userid'         => 7,
            'serverhostname' => 'paneldns.test',
            'serversecure'   => true,
            'password'       => 'short',
            'configoption1'  => '5',
            'configoption2'  => '100',
            'configoption3'  => 'on',
            'clientsdetails' => ['firstname' => 'Jane', 'lastname' => 'Doe', 'email' => 'jane@example.com'],
        ], $over);
    }

    private function dedicatedIpUpdates(): array
    {
        return array_values(array_filter(
            Capsule::ops('update', 'tblhosting'),
            fn (FakeQuery $q) => isset($q->arg['dedicatedip'])
        ));
    }

    // ── createPayload ──────────────────────────────────────────────────────────

    public function test_create_payload_omits_a_password_panel_dns_would_reject(): void
    {
        $limits = ['zone_limit' => 5, 'max_records' => 100];
        $short = PanelDnsResellerService::createPayload(['email' => 'a@b.c'], 'abc123', $limits, '');
        $this->assertArrayNotHasKey('password', $short, 'PanelDNS requires 12+ characters (PASSWORD-01)');

        $long = PanelDnsResellerService::createPayload(['email' => 'a@b.c'], 'twelve-chars', $limits, '');
        $this->assertSame('twelve-chars', $long['password']);
    }

    public function test_create_payload_stamps_consent_only_with_a_version(): void
    {
        $limits = ['zone_limit' => 5, 'max_records' => 100];
        $with = PanelDnsResellerService::createPayload(['email' => 'a@b.c'], '', $limits, '2026-04-01');
        $this->assertTrue($with['terms_acknowledged']);
        $this->assertSame('2026-04-01', $with['terms_version']);

        $without = PanelDnsResellerService::createPayload(['email' => 'a@b.c'], '', $limits, '');
        $this->assertArrayNotHasKey('terms_version', $without);
        $this->assertSame('a@b.c', $without['name'], 'falls back to the email when there is no name');
    }

    // ── CreateAccount ──────────────────────────────────────────────────────────

    public function test_create_account_creates_stores_the_id_and_sends_the_welcome_email(): void
    {
        $this->api->on('POST', '/api/v1/sub-clients', FakeApi::json(201, ['ok' => true, 'data' => ['id' => 77]]));

        $this->assertSame('success', PanelDnsResellerService::run('createAccount', $this->params()));

        $post = $this->api->callsTo('POST', '/api/v1/sub-clients')[0]['body'];
        $this->assertSame('2026-04-01', $post['terms_version']);
        $this->assertSame('jane@example.com', $post['email']);
        $this->assertSame(['dedicatedip' => '77'], $this->dedicatedIpUpdates()[0]->arg);

        // configoption3 is 'on' (a ticked yesno) — the welcome email must go (WELCOME-01).
        $this->assertCount(1, $this->api->callsTo('POST', '/api/v1/sub-clients/77/sso-token'));
        $this->assertSame('SendEmail', $GLOBALS['__local_api'][0][0] ?? null);
    }

    public function test_create_account_still_provisions_when_the_legal_version_is_unavailable(): void
    {
        $this->api
            ->on('GET', '/api/v1/legal-version', FakeApi::json(500, ['message' => 'Server Error']))
            ->on('POST', '/api/v1/sub-clients', FakeApi::json(201, ['ok' => true, 'data' => ['id' => 77]]));

        $this->assertSame('success', PanelDnsResellerService::run('createAccount', $this->params(['configoption3' => ''])));
        $this->assertArrayNotHasKey('terms_version', $this->api->callsTo('POST', '/api/v1/sub-clients')[0]['body']);
        $this->assertSame([], $this->api->callsTo('POST', '/api/v1/sub-clients/77/sso-token'), 'unticked = no welcome email');
    }

    public function test_create_account_returns_the_licence_banner_when_locked(): void
    {
        $this->api->on('GET', '/api/v1/licence-status', FakeApi::json(200, ['ok' => true, 'data' => [
            'sub_status' => 'cancelled', 'modules_unlocked' => [], 'expires_at' => null,
        ]]));
        $this->assertStringContainsString('subscription cancelled', PanelDnsResellerService::run('createAccount', $this->params()));
        $this->assertSame([], $this->api->callsTo('POST', '/api/v1/sub-clients'));
    }

    public function test_create_account_reprovisions_when_the_stored_id_is_gone(): void
    {
        $this->api
            ->on('PATCH', '/api/v1/sub-clients/12', FakeApi::json(404, ['ok' => false, 'error' => 'Not found.', 'code' => 404]))
            ->on('POST', '/api/v1/sub-clients', FakeApi::json(201, ['ok' => true, 'data' => ['id' => 77]]));

        $params = $this->params(['configoption3' => '']);
        $params['model'] = (object) ['dedicatedip' => '12'];

        $this->assertSame('success', PanelDnsResellerService::run('createAccount', $params));
        $this->assertSame(['dedicatedip' => '77'], $this->dedicatedIpUpdates()[0]->arg);
    }

    public function test_create_account_surfaces_a_validation_message(): void
    {
        $this->api->on('POST', '/api/v1/sub-clients', FakeApi::json(422, ['ok' => false, 'error' => 'Your plan allows 10 sub-clients.', 'code' => 422]));
        $this->assertSame('Your plan allows 10 sub-clients.', PanelDnsResellerService::run('createAccount', $this->params()));
    }

    // ── Duplicate email (finding 8) ────────────────────────────────────────────

    /** POST answers "email taken"; the search finds $subClient; tblhosting links are $links. */
    private function duplicate(array $subClient, array $links): void
    {
        $this->api
            ->on('POST', '/api/v1/sub-clients', FakeApi::json(422, [
                'message' => 'The email has already been taken.',
                'errors'  => ['email' => ['The email has already been taken.']],
            ]))
            ->on('GET', '/api/v1/sub-clients', FakeApi::json(200, [
                'ok' => true, 'data' => [$subClient], 'meta' => ['page' => 1, 'per_page' => 50, 'total' => 1],
            ]))
            ->on('PATCH', '/api/v1/sub-clients/77', FakeApi::json(200, ['ok' => true, 'data' => $subClient]));

        Capsule::$handler = function (FakeQuery $q, string $op) use ($links) {
            if ($op === 'get' && $q->hasWhere('tblhosting.dedicatedip')) {
                return $links;
            }
            if ($op === 'value') return '';
            return null;
        };
    }

    private function subClient(int $orgId = 3): array
    {
        return ['id' => 77, 'org_id' => $orgId, 'email' => 'Jane@Example.com', 'status' => 'suspended'];
    }

    public function test_second_service_for_the_same_client_gets_a_clear_message_and_links_nothing(): void
    {
        $this->duplicate($this->subClient(), [(object) ['id' => 41, 'userid' => 7, 'domainstatus' => 'Active', 'notes' => '']]);

        $msg = PanelDnsResellerService::run('createAccount', $this->params());

        $this->assertStringContainsString('already has a PanelDNS account (sub-client #77) on service #41', $msg);
        $this->assertStringContainsString('one sub-client per email', $msg);
        $this->assertSame([], $this->api->callsTo('PATCH', '/api/v1/sub-clients/77'));
        $this->assertSame([], $this->dedicatedIpUpdates());

        // The link lookup is scoped to this module and server, excluding this service.
        $lookup = array_values(array_filter(Capsule::ops('get', 'tblhosting'), fn ($q) => $q->hasWhere('tblhosting.dedicatedip')))[0];
        $this->assertTrue($lookup->hasWhere('tblproducts.servertype', 'in', PanelDnsResellerHooks::SERVERTYPES));
        $this->assertTrue($lookup->hasWhere('tblhosting.dedicatedip', '=', '77'));
        $this->assertTrue($lookup->hasWhere('tblhosting.id', '!=', 500));
        $this->assertTrue($lookup->hasWhere('tblservers.hostname', '=', 'paneldns.test'));
    }

    public function test_unlinked_existing_sub_client_is_not_auto_linked(): void
    {
        $this->duplicate($this->subClient(), []);
        $msg = PanelDnsResellerService::run('createAccount', $this->params());

        $this->assertStringContainsString('no WHMCS service is linked to it', $msg);
        $this->assertStringContainsString("enter 77 in this service's Dedicated IP field", $msg);
        $this->assertSame([], $this->dedicatedIpUpdates());
    }

    public function test_sub_client_from_another_org_is_never_linked(): void
    {
        $this->duplicate($this->subClient(99), [(object) ['id' => 41, 'userid' => 7, 'domainstatus' => 'Terminated', 'notes' => '']]);
        $msg = PanelDnsResellerService::run('createAccount', $this->params());

        $this->assertStringContainsString("could not be confirmed as belonging to this server's organisation", $msg);
        $this->assertSame([], $this->api->callsTo('PATCH', '/api/v1/sub-clients/77'));
        $this->assertSame([], $this->dedicatedIpUpdates());
    }

    public function test_reorder_by_the_same_client_after_termination_relinks_and_cancels_the_grace_delete(): void
    {
        $this->duplicate($this->subClient(), [(object) [
            'id' => 41, 'userid' => 7, 'domainstatus' => 'Terminated', 'notes' => "old notes\n[paneldns-grace:2099-01-01]",
        ]]);

        $this->assertSame('success', PanelDnsResellerService::run('createAccount', $this->params(['configoption3' => ''])));

        $patch = $this->api->callsTo('PATCH', '/api/v1/sub-clients/77');
        $this->assertCount(1, $patch);
        $this->assertSame(['status' => 'active', 'zone_limit' => 5, 'max_records' => 100], $patch[0]['body']);
        $this->assertSame(['dedicatedip' => '77'], $this->dedicatedIpUpdates()[0]->arg);

        $notes = array_values(array_filter(Capsule::ops('update', 'tblhosting'), fn ($q) => isset($q->arg['notes']) && $q->hasWhere('id', '=', 41)));
        $this->assertSame(['notes' => 'old notes'], $notes[0]->arg, 'grace marker removed from the old service');
    }

    /** @dataProvider decisionProvider */
    public function test_duplicate_email_decision(array $links, int $user, string $action, string $reason): void
    {
        $d = PanelDnsResellerService::duplicateEmailDecision($links, $user);
        $this->assertSame($action, $d['action']);
        $this->assertSame($reason, $d['reason']);
    }

    public static function decisionProvider(): array
    {
        $svc = fn (int $id, int $user, string $status, string $notes = '') => (object) ['id' => $id, 'userid' => $user, 'domainstatus' => $status, 'notes' => $notes];
        return [
            'no links'                       => [[], 7, 'refuse', 'unlinked'],
            'live, same client'              => [[$svc(1, 7, 'Active')], 7, 'refuse', 'in_use_same_client'],
            'suspended, same client'         => [[$svc(1, 7, 'Suspended')], 7, 'refuse', 'in_use_same_client'],
            'pending, other client'          => [[$svc(1, 8, 'Pending')], 7, 'refuse', 'in_use_other_client'],
            'terminated, other client'       => [[$svc(1, 8, 'Terminated')], 7, 'refuse', 'other_client'],
            'terminated, same client'        => [[$svc(1, 7, 'Terminated')], 7, 'link', 'same_client_terminated'],
            'mixed: one live wins'           => [[$svc(1, 7, 'Terminated'), $svc(2, 7, 'Active')], 7, 'refuse', 'in_use_same_client'],
            'unknown current user'           => [[$svc(1, 7, 'Terminated')], 0, 'refuse', 'other_client'],
        ];
    }

    // ── SSO: WHMCS reads redirectTo ────────────────────────────────────────────

    public function test_sso_results_use_the_redirect_to_key_whmcs_reads(): void
    {
        $params = $this->params();
        $params['model'] = (object) ['dedicatedip' => '77'];

        foreach (['adminSso', 'clientSso'] as $fn) {
            $r = PanelDnsResellerService::$fn($params);
            $this->assertTrue($r['success'], $fn);
            $this->assertSame('https://paneldns.test/portal/sso/consume?token=x', $r['redirectTo'], $fn);
            $this->assertArrayNotHasKey('redirectUrl', $r, $fn);
        }
    }

    public function test_sso_failure_returns_an_error_message(): void
    {
        $this->api->on('POST', '/api/v1/sub-clients/77/sso-token', FakeApi::json(422, ['ok' => false, 'error' => 'Sub-client is not active.', 'code' => 422]));
        $params = $this->params();
        $params['model'] = (object) ['dedicatedip' => '77'];

        $r = PanelDnsResellerService::adminSso($params);
        $this->assertFalse($r['success']);
        $this->assertSame('Sub-client is not active.', $r['errorMsg']);
    }

    public function test_welcome_email_passes_customvars_as_an_array(): void
    {
        $this->api->on('POST', '/api/v1/sub-clients', FakeApi::json(201, ['ok' => true, 'data' => ['id' => 77]]));
        PanelDnsResellerService::run('createAccount', $this->params());

        [$command, $vars] = $GLOBALS['__local_api'][0];
        $this->assertSame('SendEmail', $command);
        $this->assertSame('PanelDNS Reseller Welcome', $vars['messagename']);
        $this->assertIsArray($vars['customvars'], 'WHMCS documents customvars as an array (MERGE-VARS-01)');
        $this->assertSame('https://paneldns.test/portal/sso/consume?token=x', $vars['customvars']['paneldns_login_url']);
        $this->assertSame('https://paneldns.test/portal/acme', $vars['customvars']['paneldns_portal_url']);
        $this->assertSame("ns1.acme.test\nns2.acme.test", $vars['customvars']['paneldns_nameservers']);
    }

    // ── ListAccounts paging (finding 7) ────────────────────────────────────────

    private function pagedList(int $total, ?int $failOnPage = null): void
    {
        $this->api->on('GET', '/api/v1/sub-clients', function (array $q) use ($total, $failOnPage) {
            $page = max(1, (int) ($q['page'] ?? 1));
            if ($page === $failOnPage) return FakeApi::json(503, ['message' => 'Service Unavailable']);
            $rows = [];
            for ($i = ($page - 1) * 50 + 1; $i <= min($total, $page * 50); $i++) {
                $rows[] = ['id' => $i, 'email' => "c{$i}@x.test", 'name' => "C{$i}", 'status' => $i % 10 ? 'active' : 'suspended', 'zone_limit' => 5];
            }
            return FakeApi::json(200, ['ok' => true, 'data' => $rows, 'meta' => ['page' => $page, 'per_page' => 50, 'total' => $total]]);
        });
    }

    public function test_list_accounts_returns_every_sub_client_not_just_the_first_50(): void
    {
        $this->pagedList(120);
        $r = PanelDnsResellerService::listAccounts($this->params());

        $this->assertTrue($r['success']);
        $this->assertCount(120, $r['accounts']);
        $this->assertSame('120', $r['accounts'][119]['uniqueIdentifier']);
        $this->assertTrue($r['accounts'][9]['suspended']);
        $this->assertCount(3, $this->api->callsTo('GET', '/api/v1/sub-clients'));
    }

    public function test_list_accounts_fails_rather_than_returning_a_partial_list(): void
    {
        $this->pagedList(120, 2);
        $r = PanelDnsResellerService::listAccounts($this->params());
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('Could not list PanelDNS sub-clients', $r['error']);
    }

    // ── Client-area routing ────────────────────────────────────────────────────

    public function test_requested_action_comes_from_the_custom_action_request_parameter(): void
    {
        $this->assertSame('zones', PanelDnsResellerService::requestedAction([], ['customAction' => 'zones']));
        $this->assertSame('records', PanelDnsResellerService::requestedAction(['customAction' => 'records'], []));
        $this->assertSame('', PanelDnsResellerService::requestedAction([], ['a' => 'zones', 'modop' => 'custom']));
        $this->assertSame('', PanelDnsResellerService::requestedAction([], ['customAction' => ['x']]));
        $this->assertContains('do-dnssec-toggle', PanelDnsResellerService::EMBEDDED_ACTIONS);
    }

    public function test_templates_route_through_custom_action_only(): void
    {
        foreach (glob(__DIR__ . '/../modules/servers/paneldnsreseller/templates/client/*.tpl') as $tpl) {
            $src = (string) file_get_contents($tpl);
            $this->assertStringNotContainsString('modop=custom', $src, basename($tpl));
            preg_match_all('/customAction=([a-z\-]+)/', $src, $m);
            foreach ($m[1] as $action) {
                $this->assertTrue(
                    $action === 'sso' || in_array($action, PanelDnsResellerService::EMBEDDED_ACTIONS, true),
                    basename($tpl) . " links to unknown action '{$action}'"
                );
            }
        }
    }

    public function test_overview_no_longer_offers_the_consent_banner(): void
    {
        $this->api
            ->on('GET', '/api/v1/sub-clients/77/summary', FakeApi::json(200, ['ok' => true, 'data' => [
                'sub_client' => ['id' => 77, 'status' => 'active'], 'usage' => ['zones' => 1, 'records' => 3], 'limits' => ['zones' => 5, 'records' => 100],
            ]]))
            ->on('GET', '/api/v1/zones', FakeApi::json(200, ['ok' => true, 'data' => [
                ['id' => 1, 'name' => 'ok.test', 'status' => 'active'], ['id' => 2, 'name' => 'bad.test', 'status' => 'error'],
            ], 'meta' => ['page' => 1, 'per_page' => 50, 'total' => 2]]));

        $params = $this->params();
        $params['model'] = (object) ['dedicatedip' => '77'];
        $out = PanelDnsResellerService::clientArea($params);

        $this->assertSame('templates/client/overview', $out['templatefile']);
        $this->assertArrayNotHasKey('paneldns_requires_consent', $out['vars']);
        $this->assertSame([['name' => 'bad.test', 'status' => 'error']], $out['vars']['paneldns_zones_health']);
        $this->assertSame(['ns1.acme.test', 'ns2.acme.test'], $out['vars']['paneldns_nameservers']);

        // A second view inside the cache window makes no API calls at all.
        $before = count($this->api->calls);
        PanelDnsResellerService::clientArea($params);
        $this->assertCount($before, $this->api->calls);
    }
}
