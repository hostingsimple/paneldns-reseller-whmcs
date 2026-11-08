<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/**
 * @covers \PanelDnsResellerHooks
 */
final class PanelDnsResellerHooksTest extends TestCase
{
    protected function setUp(): void
    {
        Capsule::reset();
        PanelDnsResellerApi::resetRateLimitState();
        PanelDnsResellerHooks::$apiFactory = null;
        $GLOBALS['__activity_log'] = [];
    }

    protected function tearDown(): void
    {
        Capsule::reset();
        PanelDnsResellerHooks::$apiFactory = null;
    }

    // ── Addon zone count parsing ───────────────────────────────────────────────

    /**
     * The regex that parses extra zones from an addon name is inline in
     * onAddonChange(). We test the exact pattern here to guard against regressions.
     *
     * @dataProvider addonNameProvider
     */
    public function test_addon_zone_count_regex(string $name, int $expectedZones): void
    {
        preg_match('/\+?\s*(\d+)\s*zone/i', $name, $m);
        $parsed = isset($m[1]) ? (int) $m[1] : 5;
        $this->assertSame($expectedZones, $parsed, "Addon name: '{$name}'");
    }

    public static function addonNameProvider(): array
    {
        return [
            'standard format'       => ['PanelDNS +10 Zones',   10],
            'no plus sign'          => ['PanelDNS 10 Zones',     10],
            'lowercase'             => ['paneldns +5 zone',       5],
            'extra whitespace'      => ['PanelDNS +  25  zones', 25],
            'zone singular'         => ['DNS +1 Zone',            1],
            'large number'          => ['PanelDNS +100 Zones',  100],
            'no number — fallback'  => ['PanelDNS Zone Pack',     5],
            'number not near zone'  => ['3 Extras DNS Pack',       5], // "zone" not present
            'number before word'    => ['50 Zones Addon',         50],
        ];
    }

    // ── Grace marker helpers ───────────────────────────────────────────────────

    /** @dataProvider graceMarkerProvider */
    public function test_grace_deadline_is_parsed_from_the_marker(string $notes, ?string $expected): void
    {
        $this->assertSame($expected, PanelDnsResellerHooks::graceDeadline($notes));
    }

    public static function graceMarkerProvider(): array
    {
        return [
            'valid marker'           => ['[paneldns-grace:2026-06-15]', '2026-06-15'],
            'marker with other text' => ["PanelDNS Nameservers:\nns1.x\n[paneldns-grace:2026-01-01]", '2026-01-01'],
            'no marker'              => ['Plain notes', null],
            'malformed marker'       => ['[paneldns-grace:not-a-date]', null],
            'empty notes'            => ['', null],
        ];
    }

    public function test_grace_marker_is_appended_and_stripped_without_losing_notes(): void
    {
        $notes = "PanelDNS Nameservers:\nns1.example.com\nns2.example.com";
        $marked = PanelDnsResellerHooks::withGraceMarker($notes, '2026-12-01');

        $this->assertStringStartsWith($notes, $marked);
        $this->assertSame('2026-12-01', PanelDnsResellerHooks::graceDeadline($marked));
        $this->assertSame($notes, PanelDnsResellerHooks::stripGraceMarker($marked));

        // Re-marking replaces the old marker rather than stacking a second one.
        $again = PanelDnsResellerHooks::withGraceMarker($marked, '2027-01-01');
        $this->assertSame(1, substr_count($again, '[paneldns-grace:'));
        $this->assertSame('2027-01-01', PanelDnsResellerHooks::graceDeadline($again));
    }

    /** @dataProvider graceResultProvider */
    public function test_grace_delete_success_check(array $resp, bool $expected): void
    {
        $this->assertSame($expected, PanelDnsResellerHooks::graceDeleteSucceeded($resp));
    }

    public static function graceResultProvider(): array
    {
        return [
            'deleted'        => [['ok' => true,  'status' => 200], true],
            'already gone'   => [['ok' => false, 'status' => 404], true],
            'server error'   => [['ok' => false, 'status' => 500], false],
            'refused'        => [['ok' => false, 'status' => 403], false],
            'rate limited'   => [['ok' => false, 'status' => 429], false],
            'network error'  => [['ok' => false, 'status' => 0],   false],
        ];
    }

    // ── Grace cron: module filter + result handling ────────────────────────────

    private function graceRow(string $deadline, int $serviceId = 9, int $subClientId = 42): object
    {
        return (object) [
            'service_id'      => $serviceId,
            'sub_client_id'   => (string) $subClientId,
            'notes'           => "keep me\n[paneldns-grace:{$deadline}]",
            'server_hostname' => 'paneldns.test',
            'server_key'      => 'k',
            'server_secure'   => 1,
            'server_port'     => 443,
        ];
    }

    /** Runs the cron against one row, with DELETE answering $deleteResponse. */
    private function runGraceCron(object $row, array $deleteResponse): FakeApi
    {
        $api = (new FakeApi())->on('DELETE', '/api/v1/sub-clients/' . (int) $row->sub_client_id, $deleteResponse);
        PanelDnsResellerHooks::$apiFactory = fn () => $api;
        Capsule::$handler = fn (FakeQuery $q, string $op) => $op === 'get' ? [$row] : null;

        PanelDnsResellerHooks::processExpiredGracePeriods();
        return $api;
    }

    public function test_grace_cron_only_selects_this_modules_services(): void
    {
        $this->runGraceCron($this->graceRow('2000-01-01'), FakeApi::json(200, ['ok' => true]));

        $select = Capsule::ops('get', 'tblhosting')[0];
        $this->assertTrue($select->hasWhere('tblproducts.servertype', 'in', ['paneldnsreseller', 'paneldns-reseller', 'paneldns_reseller']),
            'grace cron must filter to this module (GRACE-03)');
        $this->assertTrue($select->hasWhere('tblhosting.domainstatus', '=', 'Terminated'));
        $this->assertTrue($select->hasWhere('tblhosting.notes', 'like', '%[paneldns-grace:%'));
    }

    public function test_grace_cron_clears_only_the_marker_after_a_successful_delete(): void
    {
        $api = $this->runGraceCron($this->graceRow('2000-01-01'), FakeApi::json(200, ['ok' => true]));

        $this->assertCount(1, $api->callsTo('DELETE', '/api/v1/sub-clients/42'));
        $updates = Capsule::ops('update', 'tblhosting');
        $this->assertCount(1, $updates);
        $this->assertSame(['notes' => 'keep me'], $updates[0]->arg);
    }

    public function test_grace_cron_keeps_the_marker_when_the_delete_fails(): void
    {
        $api = $this->runGraceCron(
            $this->graceRow('2000-01-01'),
            FakeApi::json(500, ['ok' => false, 'error' => 'Provider unavailable.', 'code' => 500])
        );

        $this->assertCount(1, $api->callsTo('DELETE', '/api/v1/sub-clients/42'));
        $this->assertSame([], Capsule::ops('update', 'tblhosting'), 'marker must stay so the next cron retries (GRACE-04)');
        $this->assertStringContainsString('will retry', strtolower(implode("\n", $GLOBALS['__activity_log'])));
    }

    public function test_grace_cron_treats_404_as_already_deleted(): void
    {
        $this->runGraceCron($this->graceRow('2000-01-01'), FakeApi::json(404, ['ok' => false, 'error' => 'Not found.', 'code' => 404]));
        $this->assertCount(1, Capsule::ops('update', 'tblhosting'));
    }

    public function test_grace_cron_skips_future_deadlines(): void
    {
        $api = $this->runGraceCron($this->graceRow(date('Y-m-d', strtotime('+3 days'))), FakeApi::json(200, ['ok' => true]));
        $this->assertSame([], $api->calls);
        $this->assertSame([], Capsule::ops('update', 'tblhosting'));
    }

    // ── ClientEdit: only fields PanelDNS accepts ───────────────────────────────

    public function test_client_edit_patch_sends_only_name(): void
    {
        $this->assertSame(['name' => 'Jane Doe'], PanelDnsResellerHooks::clientEditPatch('Jane', 'Doe'));
        $this->assertSame(['name' => 'Jane'], PanelDnsResellerHooks::clientEditPatch(' Jane ', ''));
        $this->assertSame([], PanelDnsResellerHooks::clientEditPatch('', ''));
        $this->assertSame(128, mb_strlen(PanelDnsResellerHooks::clientEditPatch(str_repeat('a', 100), str_repeat('b', 100))['name']));
    }

    public function test_client_edit_patches_name_without_email_or_locale(): void
    {
        $api = (new FakeApi())->on('PATCH', '/api/v1/sub-clients/42', FakeApi::json(200, ['ok' => true, 'data' => []]));
        PanelDnsResellerHooks::$apiFactory = fn () => $api;
        Capsule::$handler = fn (FakeQuery $q, string $op) => $op === 'get' ? [(object) [
            'sub_client_id' => '42', 'server_hostname' => 'paneldns.test', 'server_key' => 'k',
            'server_secure' => 1, 'server_port' => 443,
        ]] : null;

        PanelDnsResellerHooks::onClientEdit(7, 'Jane', 'Doe');

        $patch = $api->callsTo('PATCH', '/api/v1/sub-clients/42');
        $this->assertCount(1, $patch);
        $this->assertSame(['name' => 'Jane Doe'], $patch[0]['body']);
        $this->assertTrue(Capsule::ops('get', 'tblhosting')[0]->hasWhere('tblproducts.servertype', 'in', ['paneldnsreseller', 'paneldns-reseller', 'paneldns_reseller']));
    }

    // ── Domain delete: exact-name lookup, paged ────────────────────────────────

    public function test_domain_delete_finds_the_zone_on_a_later_page(): void
    {
        $api = new FakeApi();
        $api->on('GET', '/api/v1/zones', function (array $q) {
            // An older server ignores ?name= and lists every zone of the sub-client.
            $page = (int) ($q['page'] ?? 1);
            $rows = [];
            for ($i = 1; $i <= ($page === 1 ? 50 : 3); $i++) {
                $n = ($page - 1) * 50 + $i;
                $rows[] = ['id' => $n, 'name' => $n === 52 ? 'example.com' : "z{$n}.test", 'sub_client_id' => 42];
            }
            return FakeApi::json(200, ['ok' => true, 'data' => $rows, 'meta' => ['page' => $page, 'per_page' => 50, 'total' => 53]]);
        });
        $api->on('DELETE', '/api/v1/zones/52', FakeApi::json(200, ['ok' => true, 'data' => null]));
        PanelDnsResellerHooks::$apiFactory = fn () => $api;
        Capsule::$handler = fn (FakeQuery $q, string $op) => $op === 'first' ? (object) [
            'service_id' => 1, 'sub_client_id' => '42', 'server_hostname' => 'paneldns.test', 'server_key' => 'k',
            'server_secure' => 1, 'server_port' => 443, 'auto_create' => 'on', 'auto_delete' => 'on',
        ] : null;

        PanelDnsResellerHooks::onDomainEvent(7, 'Example.com', 'delete');

        $this->assertSame('example.com', $api->callsTo('GET', '/api/v1/zones')[0]['query']['name']);
        $this->assertCount(1, $api->callsTo('DELETE', '/api/v1/zones/52'));
    }

    public function test_every_hook_query_filters_on_the_new_module_name(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../modules/servers/paneldnsreseller/lib/PanelDnsResellerHooks.php');
        $this->assertDoesNotMatchRegularExpression("/servertype',\s*'paneldns[-_]reseller'/", $src);
        $this->assertSame(5, substr_count($src, "->whereIn('tblproducts.servertype', self::SERVERTYPES)"));
        $this->assertSame('paneldnsreseller', PanelDnsResellerHooks::MODULE);
        // The filters match the legacy names until migrated, and never a platform name.
        $this->assertSame(
            array_merge([PanelDnsResellerMigration::MODULE], PanelDnsResellerMigration::LEGACY_NAMES),
            PanelDnsResellerHooks::SERVERTYPES
        );
        $this->assertSame(PanelDnsResellerHooks::SERVERTYPES, PanelDnsResellerDriftSync::SERVERTYPES);
        foreach (PanelDnsResellerHooks::SERVERTYPES as $t) {
            $this->assertStringNotContainsString('platform', $t);
        }
    }

    /** @dataProvider yesNoProvider */
    public function test_yes_no_options_accept_what_whmcs_stores(?string $v, bool $expected): void
    {
        $this->assertSame($expected, PanelDnsResellerHooks::isYes($v));
    }

    public static function yesNoProvider(): array
    {
        return [
            'ticked (WHMCS)' => ['on', true],
            'default yes'    => ['yes', true],
            'one'            => ['1', true],
            'upper'          => ['ON', true],
            'unticked'       => ['', false],
            'no'             => ['no', false],
            'null'           => [null, false],
        ];
    }
}
