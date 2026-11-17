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
        $this->assertStringNotContainsString('Bulk Sync', $msg, 'Bulk Sync no longer links by email (NO-EMAIL-LINK-01)');
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

    public function test_reorder_by_the_same_client_after_termination_is_reported_not_relinked(): void
    {
        // NO-EMAIL-LINK-02 (v1.9.4): up to 1.9.3 this re-linked and re-activated #77.
        $this->duplicate($this->subClient(), [(object) [
            'id' => 41, 'userid' => 7, 'domainstatus' => 'Terminated', 'notes' => "old notes\n[paneldns-grace:2099-01-01]",
        ]]);

        $msg = PanelDnsResellerService::run('createAccount', $this->params());

        $this->assertNotSame('success', $msg);
        $this->assertStringContainsString("was used by this client's terminated service #41", $msg);
        $this->assertStringContainsString('not linked automatically', $msg);
        $this->assertStringContainsString("enter 77 in this service's Dedicated IP field and run Create again", $msg);
        $this->assertSame([], $this->api->callsTo('PATCH', '/api/v1/sub-clients/77'), 'must not re-activate it');
        $this->assertSame([], $this->api->callsTo('POST', '/api/v1/sub-clients/77/sso-token'), 'no welcome-email SSO link');
        $this->assertSame([], $this->dedicatedIpUpdates());
        $this->assertSame([], Capsule::ops('update', 'tblhosting'), 'nothing is written — not even the old grace marker');
    }

    /**
     * NO-EMAIL-LINK-02: whatever the existing sub-client's links look like, a duplicate
     * email fails CreateAccount and never links, re-activates or mints SSO for it.
     *
     * @dataProvider decisionProvider
     */
    public function test_duplicate_email_never_links_in_any_case(array $links, int $user, string $reason): void
    {
        $this->duplicate($this->subClient(), $links);

        $msg = PanelDnsResellerService::run('createAccount', $this->params(['userid' => $user]));

        $this->assertNotSame('success', $msg);
        $this->assertStringContainsString('#77', $msg);
        $this->assertSame([], $this->api->callsTo('PATCH', '/api/v1/sub-clients/77'));
        $this->assertSame([], $this->api->callsTo('POST', '/api/v1/sub-clients/77/sso-token'));
        $this->assertSame([], $this->dedicatedIpUpdates());
    }

    /** @dataProvider decisionProvider */
    public function test_duplicate_email_decision(array $links, int $user, string $reason): void
    {
        $d = PanelDnsResellerService::duplicateEmailDecision($links, $user);
        $this->assertSame($reason, $d['reason']);
        $this->assertSame(['reason', 'service_id'], array_keys($d), 'a classification only — there is no link action');
    }

    public static function decisionProvider(): array
    {
        $svc = fn (int $id, int $user, string $status, string $notes = '') => (object) ['id' => $id, 'userid' => $user, 'domainstatus' => $status, 'notes' => $notes];
        return [
            'no links'                       => [[], 7, 'unlinked'],
            'live, same client'              => [[$svc(1, 7, 'Active')], 7, 'in_use_same_client'],
            'suspended, same client'         => [[$svc(1, 7, 'Suspended')], 7, 'in_use_same_client'],
            'pending, other client'          => [[$svc(1, 8, 'Pending')], 7, 'in_use_other_client'],
            'terminated, other client'       => [[$svc(1, 8, 'Terminated')], 7, 'other_client'],
            'terminated, same client'        => [[$svc(1, 7, 'Terminated', '[paneldns-grace:2099-01-01]')], 7, 'same_client_terminated'],
            'mixed: one live wins'           => [[$svc(1, 7, 'Terminated'), $svc(2, 7, 'Active')], 7, 'in_use_same_client'],
            'unknown current user'           => [[$svc(1, 7, 'Terminated')], 0, 'other_client'],
        ];
    }

    // ── Sub-client id comes from Dedicated IP only (NO-EMAIL-LINK-03) ──────────

    public function test_a_client_supplied_custom_field_is_never_used_as_the_sub_client_id(): void
    {
        // A product custom field that is not Admin Only can be filled in on the order form.
        $this->api->on('POST', '/api/v1/sub-clients/99/sso-token', FakeApi::json(200, ['ok' => true, 'data' => ['login_url' => 'https://paneldns.test/portal/sso/consume?token=victim']]));
        $params = $this->params(['customfields' => ['paneldns_sub_client_id' => '99']]);

        foreach (['adminSso', 'clientSso'] as $fn) {
            $r = PanelDnsResellerService::$fn($params);
            $this->assertFalse($r['success'], $fn);
            $this->assertSame('Service not provisioned yet.', $r['errorMsg'], $fn);
        }
        $this->assertSame([], $this->api->callsTo('POST', '/api/v1/sub-clients/99/sso-token'));

        // With a Dedicated IP set, that wins and the custom field is ignored.
        $params['model'] = (object) ['dedicatedip' => '77'];
        $this->assertSame('https://paneldns.test/portal/sso/consume?token=x', PanelDnsResellerService::clientSso($params)['redirectTo']);
        $this->assertSame([], $this->api->callsTo('POST', '/api/v1/sub-clients/99/sso-token'));
    }

    public function test_create_account_ignores_a_custom_field_sub_client_id(): void
    {
        $this->api
            ->on('PATCH', '/api/v1/sub-clients/99', FakeApi::json(200, ['ok' => true, 'data' => ['id' => 99]]))
            ->on('POST', '/api/v1/sub-clients', FakeApi::json(201, ['ok' => true, 'data' => ['id' => 77]]));

        $params = $this->params(['configoption3' => '', 'customfields' => ['paneldns_sub_client_id' => '99']]);
        $this->assertSame('success', PanelDnsResellerService::run('createAccount', $params));

        $this->assertSame([], $this->api->callsTo('PATCH', '/api/v1/sub-clients/99'), 'must not re-activate a client-named sub-client');
        $this->assertCount(1, $this->api->callsTo('POST', '/api/v1/sub-clients'));
        $this->assertSame(['dedicatedip' => '77'], $this->dedicatedIpUpdates()[0]->arg);
    }

    /** @dataProvider dedicatedIpProvider */
    public function test_only_a_positive_integer_dedicated_ip_counts_as_linked(string $value, bool $linked): void
    {
        $params = $this->params();
        $params['model'] = (object) ['dedicatedip' => $value];
        $this->assertSame($linked, PanelDnsResellerService::clientSso($params)['success'], var_export($value, true));
    }

    public static function dedicatedIpProvider(): array
    {
        return [
            'id'          => ['77', true],
            'padded id'   => [' 77 ', true],
            'empty'       => ['', false],
            'zero'        => ['0', false],
            'an IP'       => ['77.1.2.3', false],
            'negative'    => ['-77', false],
            'trailing'    => ['77abc', false],
        ];
    }

    // ── Bulk Sync: report email matches, never link (NO-EMAIL-LINK-01) ─────────

    /**
     * Runs Bulk Sync over $services (rows of the services join), with the sub-client
     * search answering $subClients and tblhosting links answering $links.
     */
    private function bulkSync(array $services, array $subClients, array $links = []): string
    {
        $this->api
            ->on('GET', '/api/ping', FakeApi::json(200, ['ok' => true]))
            ->on('GET', '/api/v1/sub-clients', FakeApi::json(200, [
                'ok' => true, 'data' => $subClients, 'meta' => ['page' => 1, 'per_page' => 50, 'total' => count($subClients)],
            ]))
            ->on('POST', '/api/v1/sub-clients', FakeApi::json(201, ['ok' => true, 'data' => ['id' => 88]]));

        Capsule::$handler = function (FakeQuery $q, string $op) use ($services, $links) {
            if ($op !== 'get') return null;
            if ($q->table === 'tblhosting as h') return $services;
            if ($q->hasWhere('tblhosting.dedicatedip')) return $links;
            return [];
        };

        return PanelDnsResellerService::bulkSyncForServer([
            'serverid' => 4, 'serverhostname' => 'paneldns.test', 'serversecure' => true, 'serveraccesshash' => 'k',
        ]);
    }

    private function bulkService(int $id, string $email, string $dedicatedIp = ''): object
    {
        return (object) [
            'serviceid' => $id, 'userid' => 7, 'dedicatedip' => $dedicatedIp, 'domainstatus' => 'Active',
            'email' => $email, 'firstname' => 'Mallory', 'lastname' => 'M', 'zone_limit' => '5', 'max_records' => '100',
        ];
    }

    public function test_bulk_sync_reports_an_email_match_and_never_links_it(): void
    {
        // The victim's sub-client #77 is not linked to any WHMCS service; a WHMCS client
        // has set their (unverified) email to the victim's address.
        $out = $this->bulkSync([$this->bulkService(500, 'jane@example.com')], [$this->subClient()]);

        $this->assertStringContainsString('Created: 0 | Needs review (not linked): 1', $out);
        $this->assertStringContainsString("service #500: sub-client #77 has this client's email", $out);
        $this->assertStringContainsString('NOT linked', $out);
        $this->assertStringNotContainsString('Linked:', $out);
        $this->assertSame([], $this->dedicatedIpUpdates(), 'no service may be pointed at the matched sub-client');
        $this->assertSame([], $this->api->callsTo('POST', '/api/v1/sub-clients'), 'and no duplicate is attempted');
        $this->assertSame([], $this->api->callsTo('PATCH', '/api/v1/sub-clients/77'));
        $this->assertSame([], $this->api->callsTo('POST', '/api/v1/sub-clients/77/sso-token'));
    }

    public function test_bulk_sync_notes_when_the_matched_sub_client_is_in_use(): void
    {
        $out = $this->bulkSync(
            [$this->bulkService(500, 'jane@example.com')],
            [$this->subClient()],
            [(object) ['id' => 41, 'userid' => 8, 'domainstatus' => 'Active', 'notes' => '']]
        );
        $this->assertStringContainsString('sub-client #77 has this client\'s email, already used by service #41', $out);
        $this->assertSame([], $this->dedicatedIpUpdates());
    }

    public function test_bulk_sync_creates_for_no_match_and_skips_linked_services(): void
    {
        $out = $this->bulkSync(
            [$this->bulkService(500, 'new@example.com'), $this->bulkService(501, 'jane@example.com', '77')],
            [] // no sub-client has new@example.com
        );

        $this->assertStringContainsString('Created: 1 | Needs review (not linked): 0 | Skipped (already provisioned): 1', $out);
        $post = $this->api->callsTo('POST', '/api/v1/sub-clients');
        $this->assertCount(1, $post);
        $this->assertSame('new@example.com', $post[0]['body']['email']);
        $updates = $this->dedicatedIpUpdates();
        $this->assertCount(1, $updates);
        $this->assertSame(['dedicatedip' => '88'], $updates[0]->arg, 'only the id PanelDNS issued for this service is stored');
        $this->assertTrue($updates[0]->hasWhere('id', '=', 500));
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
