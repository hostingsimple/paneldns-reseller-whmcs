<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/**
 * DRIFT-SOT-01 / DRIFT-404-01 (v1.9.6): WHMCS is the source of truth for a service's
 * status. The nightly drift sync only ever moves a service towards the more restrictive
 * state, re-suspends in PanelDNS what WHMCS has suspended, and never terminates a
 * service because PanelDNS answered 404.
 *
 * Up to 1.9.5 the sync copied the PanelDNS status into WHMCS: a WHMCS suspension was
 * lifted the night after the sub-client was re-activated in PanelDNS, an unpaid Pending
 * order became Active, and every 404 (a wrong server token gives one for every service)
 * set the service Terminated.
 *
 * @covers \PanelDnsResellerDriftSync
 */
final class DriftSyncTest extends TestCase
{
    /** @var array<int,FakeApi> server id => API */
    private array $apis = [];

    protected function setUp(): void
    {
        Capsule::reset();
        PanelDnsResellerApi::resetRateLimitState();
        PanelDnsResellerDriftSync::$apiFactory = null;
        $GLOBALS['__activity_log'] = [];
        $this->apis = [];
    }

    protected function tearDown(): void
    {
        Capsule::reset();
        PanelDnsResellerDriftSync::$apiFactory = null;
    }

    private static function row(int $serviceId, int $subClientId, string $whmcsStatus, int $serverId = 1, string $notes = ''): object
    {
        return (object) [
            'service_id' => $serviceId, 'domainstatus' => $whmcsStatus, 'paneldns_id' => (string) $subClientId,
            'server_id' => $serverId, 'notes' => $notes, 'servertype' => 'paneldnsreseller',
        ];
    }

    /** One FakeApi per server; GET /sub-clients/{id} answers from $subClients (id => status|HTTP code). */
    private function api(int $serverId, array $subClients): FakeApi
    {
        $api = new FakeApi();
        foreach ($subClients as $id => $answer) {
            $api->on('GET', "/api/v1/sub-clients/{$id}", is_int($answer)
                ? FakeApi::json($answer, ['message' => $answer === 404 ? 'Not found.' : 'Unauthenticated.'])
                : FakeApi::json(200, ['ok' => true, 'data' => ['id' => $id, 'status' => $answer]]));
            $api->on('PATCH', "/api/v1/sub-clients/{$id}", FakeApi::json(200, ['ok' => true, 'data' => ['id' => $id, 'status' => 'suspended']]));
        }
        return $this->apis[$serverId] = $api;
    }

    /** Runs the sync over $rows; each row's server id picks its FakeApi. */
    private function sync(array $rows): array
    {
        $apis = $this->apis;
        PanelDnsResellerDriftSync::$apiFactory = fn (array $server, object $row) => $apis[(int) $row->server_id];
        Capsule::$handler = function (FakeQuery $q, string $op) use ($rows) {
            if ($q->baseTable() === 'tblhosting' && $op === 'get') return $rows;
            if ($q->baseTable() === 'tblservers' && $op === 'first') {
                return (object) ['id' => $q->whereValue('id'), 'hostname' => 'paneldns.test', 'secure' => 1, 'accesshash' => 'k'];
            }
            return null;
        };
        return PanelDnsResellerDriftSync::run();
    }

    /** @return list<FakeQuery> tblhosting updates that wrote domainstatus */
    private static function statusWrites(): array
    {
        return array_values(array_filter(
            Capsule::ops('update', 'tblhosting'),
            fn (FakeQuery $q) => array_key_exists('domainstatus', (array) $q->arg)
        ));
    }

    private static function activity(): string
    {
        return implode("\n", $GLOBALS['__activity_log']);
    }

    // ── WHMCS is the source of truth ───────────────────────────────────────────

    public function test_a_whmcs_suspension_is_reasserted_in_panel_dns_not_lifted(): void
    {
        // Suspended in WHMCS (overdue, abuse, a manual hold); someone re-activated the
        // sub-client in PanelDNS.
        $api = $this->api(1, [42 => 'active']);

        $stats = $this->sync([self::row(500, 42, 'Suspended')]);

        $this->assertSame([], self::statusWrites(), 'the WHMCS suspension must not be lifted');
        $patch = $api->callsTo('PATCH', '/api/v1/sub-clients/42');
        $this->assertCount(1, $patch);
        $this->assertSame(['status' => 'suspended'], $patch[0]['body']);
        $this->assertSame(1, $stats['drift_fixed']);
    }

    public function test_a_pending_order_is_never_made_active(): void
    {
        $api = $this->api(1, [42 => 'active']);

        $this->sync([self::row(500, 42, 'Pending')]);

        $this->assertSame([], self::statusWrites());
        $this->assertSame([], $api->callsTo('PATCH', '/api/v1/sub-clients/42'));
        $this->assertStringContainsString('service #500 is Pending in WHMCS but sub-client 42 is active in PanelDNS', self::activity());
    }

    /** @dataProvider restrictiveProvider */
    public function test_a_more_restrictive_panel_dns_status_is_mirrored_into_whmcs(string $whmcs, string $remote, string $expected): void
    {
        $this->api(1, [42 => $remote]);

        $this->sync([self::row(500, 42, $whmcs)]);

        $writes = self::statusWrites();
        $this->assertCount(1, $writes);
        $this->assertSame($expected, $writes[0]->arg['domainstatus']);
        $this->assertTrue($writes[0]->hasWhere('id', '=', 500));
    }

    public static function restrictiveProvider(): array
    {
        return [
            'active → suspended'    => ['Active', 'suspended', 'Suspended'],
            'active → cancelled'    => ['Active', 'cancelled', 'Terminated'],
            'suspended → cancelled' => ['Suspended', 'cancelled', 'Terminated'],
        ];
    }

    public function test_no_combination_ever_makes_a_service_less_restricted(): void
    {
        $allowed = [
            'Active'    => [null, 'whmcs:Suspended', 'whmcs:Terminated'],
            'Suspended' => [null, 'upstream:suspend', 'whmcs:Terminated'],
            'Pending'   => [null, 'review'],
            'Completed' => [null, 'review'],
        ];
        foreach ($allowed as $whmcs => $actions) {
            foreach (['active', 'suspended', 'cancelled', 'unknown'] as $remote) {
                $this->assertContains(PanelDnsResellerDriftSync::decide($whmcs, $remote), $actions, "{$whmcs} / upstream {$remote}");
            }
        }
    }

    // ── 404: flag for review, never terminate ──────────────────────────────────

    public function test_a_single_404_flags_the_service_and_does_not_terminate_it(): void
    {
        $this->api(1, [41 => 'active', 42 => 404, 43 => 'active', 44 => 'active']);

        $stats = $this->sync([
            self::row(500, 41, 'Active'),
            self::row(501, 42, 'Active', 1, 'admin note'),
            self::row(502, 43, 'Active'),
            self::row(503, 44, 'Active'),
        ]);

        $this->assertSame([], self::statusWrites(), 'a 404 must not terminate the WHMCS service');
        $notes = array_values(array_filter(Capsule::ops('update', 'tblhosting'), fn (FakeQuery $q) => isset($q->arg['notes'])));
        $this->assertCount(1, $notes);
        $this->assertTrue($notes[0]->hasWhere('id', '=', 501));
        $this->assertSame("admin note\n[paneldns-drift:not-found]", $notes[0]->arg['notes'], 'the admin notes are kept');
        $this->assertSame(1, $stats['flagged']);
        $this->assertStringContainsString('sub-client 42 of service #501 was not found in PanelDNS', self::activity());
    }

    public function test_an_already_flagged_service_is_not_rewritten(): void
    {
        $this->api(1, [41 => 'active', 42 => 404]);

        $this->sync([self::row(500, 41, 'Active'), self::row(501, 42, 'Active', 1, "x\n[paneldns-drift:not-found]")]);

        $this->assertSame([], Capsule::ops('update', 'tblhosting'));
    }

    public function test_a_server_whose_lookups_all_404_changes_nothing_and_stops_calling(): void
    {
        // The server's access hash was replaced by a token of another PanelDNS org: every
        // sub-client lookup is a 404.
        $api = $this->api(1, [41 => 404, 42 => 404, 43 => 404, 44 => 404, 45 => 404]);
        $other = $this->api(2, [46 => 'suspended']);

        $stats = $this->sync([
            self::row(500, 41, 'Active'), self::row(501, 42, 'Active'), self::row(502, 43, 'Suspended'),
            self::row(503, 44, 'Active'), self::row(504, 45, 'Active'),
            self::row(505, 46, 'Active', 2),
        ]);

        $this->assertCount(3, $api->calls, 'stops calling the server after three 404s in a row');
        $this->assertSame(2, $stats['skipped']);
        $writes = Capsule::ops('update', 'tblhosting');
        $this->assertCount(1, $writes, 'only the other server\'s service is reconciled');
        $this->assertTrue($writes[0]->hasWhere('id', '=', 505));
        $this->assertSame('Suspended', $writes[0]->arg['domainstatus']);
        $this->assertCount(1, $other->calls);
        $this->assertStringContainsString('check that the server\'s API token belongs to the right PanelDNS organisation', self::activity());
    }

    public function test_mostly_404s_on_a_server_is_treated_as_a_token_mistake(): void
    {
        $this->api(1, [41 => 'active', 42 => 404, 43 => 404, 44 => 404]);

        $this->sync([self::row(500, 41, 'Active'), self::row(501, 42, 'Active'), self::row(502, 43, 'Active'), self::row(503, 44, 'Active')]);

        $this->assertSame([], Capsule::ops('update', 'tblhosting'), 'no flag and no status write');
        $this->assertTrue(PanelDnsResellerDriftSync::looksLikeWrongOrg(3, 4, false));
        $this->assertFalse(PanelDnsResellerDriftSync::looksLikeWrongOrg(2, 2, false), 'two 404s on a small install are flagged one by one');
        $this->assertFalse(PanelDnsResellerDriftSync::looksLikeWrongOrg(3, 6, false));
    }

    public function test_a_refused_token_stops_the_server_after_one_call(): void
    {
        $api = $this->api(1, [41 => 401, 42 => 'suspended', 43 => 'suspended']);

        $stats = $this->sync([self::row(500, 41, 'Active'), self::row(501, 42, 'Active'), self::row(502, 43, 'Active')]);

        $this->assertCount(1, $api->calls);
        $this->assertSame([], Capsule::ops('update', 'tblhosting'));
        $this->assertSame(2, $stats['skipped']);
        $this->assertStringContainsString('PanelDNS refused the API token (HTTP 401/403)', self::activity());
    }

    public function test_the_docblock_no_longer_promises_the_opposite_of_the_code(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../shared/DriftSync.php');
        $this->assertStringNotContainsString('Fix: stamp WHMCS service Terminated', $src);
        $this->assertStringContainsString('DRIFT-SOT-01', $src);
    }
}
