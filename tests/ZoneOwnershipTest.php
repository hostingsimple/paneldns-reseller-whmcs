<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

require_once __DIR__ . '/../modules/servers/paneldnsreseller/paneldnsreseller.php'; // ConfigOptions

if (!function_exists('add_hook')) {
    /** Records hooks.php's registrations so a test can fire them. */
    function add_hook(string $point, int $priority, callable $fn): void
    {
        $GLOBALS['__hooks'][$point][] = $fn;
    }
}

/**
 * ZONE-OWN-01..05 (v1.9.5): a customer can create a zone only for a domain on their own
 * WHMCS account. Up to 1.9.4 any customer could add any name — a subdomain of another
 * customer's zone, or a domain they did not own — and the reseller's shared nameservers
 * answered for it from the customer's zone.
 *
 * @covers \PanelDnsEmbeddedDnsManager
 * @covers \PanelDnsResellerHooks
 */
final class ZoneOwnershipTest extends TestCase
{
    private const CLIENT = 7;
    private const SUB    = 42;

    protected function setUp(): void
    {
        Capsule::reset();
        \WHMCS\Cache\Store::clear();
        PanelDnsResellerApi::resetRateLimitState();
        PanelDnsResellerHooks::$apiFactory = null;
        $GLOBALS['__activity_log'] = [];
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        Capsule::reset();
        \WHMCS\Cache\Store::clear();
        PanelDnsResellerHooks::$apiFactory = null;
        unset($_SERVER['REQUEST_METHOD']);
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private static function domain(string $name, string $status = 'Active', int $userId = self::CLIENT, int $id = 3): object
    {
        return (object) ['id' => $id, 'userid' => $userId, 'domain' => $name, 'status' => $status];
    }

    private static function zone(int $id, string $name, ?int $subClientId): array
    {
        return ['id' => $id, 'name' => $name, 'status' => 'active', 'sub_client_id' => $subClientId];
    }

    /**
     * A stand-in for GET /api/v1/zones: ?name= exact, ?search= substring, ?sub_client_id=,
     * 50 per page with meta — as PanelDNS's V1 ZoneController::index answers.
     */
    private static function zoneIndex(array $zones): \Closure
    {
        return function (array $q) use ($zones) {
            $rows = array_values(array_filter($zones, function (array $z) use ($q) {
                if (isset($q['name']) && !isset($q['search']) && $z['name'] !== strtolower($q['name'])) return false;
                if (isset($q['search']) && !isset($q['name']) && !str_contains($z['name'], $q['search'])) return false;
                if (isset($q['sub_client_id']) && (int) $z['sub_client_id'] !== (int) $q['sub_client_id']) return false;
                return true;
            }));
            $page = max(1, (int) ($q['page'] ?? 1));
            return FakeApi::json(200, [
                'ok' => true,
                'data' => array_slice($rows, ($page - 1) * 50, 50),
                'meta' => ['page' => $page, 'per_page' => 50, 'total' => count($rows)],
            ]);
        };
    }

    /** tbldomains as a list of rows; honours where(userid), where(id), whereIn(domain). */
    private static function useDomains(array $domains, ?object $service = null): void
    {
        Capsule::$handler = function (FakeQuery $q, string $op) use ($domains, $service) {
            if ($q->baseTable() === 'tblhosting') {
                return $op === 'first' ? $service : null;
            }
            if ($q->baseTable() !== 'tbldomains') return null;
            $rows = array_values(array_filter($domains, function (object $d) use ($q) {
                if ($q->hasWhere('userid') && $d->userid !== $q->whereValue('userid')) return false;
                if ($q->hasWhere('id') && $d->id !== $q->whereValue('id')) return false;
                if ($q->hasWhere('domain', 'in') && !in_array(strtolower($d->domain), $q->whereValue('domain'), true)) return false;
                return true;
            }));
            return match ($op) {
                'get'   => $rows,
                'first' => $rows[0] ?? null,
                default => null,
            };
        };
    }

    private static function service(): object
    {
        return (object) [
            'service_id' => 500, 'sub_client_id' => (string) self::SUB, 'server_hostname' => 'paneldns.test',
            'server_key' => 'k', 'server_secure' => 1, 'server_port' => 443,
            'auto_create' => 'on', 'auto_delete' => '',
        ];
    }

    /** POST do-zone-create as client #7 on service #500 (sub-client 42). */
    private function createZone(FakeApi $api, string $name, array $params = []): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['paneldns_csrf_500'] = 'tok';
        $_POST = ['csrf' => 'tok', 'name' => $name];
        return (new PanelDnsEmbeddedDnsManager($api, self::SUB, $params + ['serviceid' => 500, 'userid' => self::CLIENT]))
            ->handle('do-zone-create');
    }

    private static function api(array $zones = [], ?array $createResponse = null): FakeApi
    {
        return (new FakeApi())
            ->on('GET', '/api/v1/zones', self::zoneIndex($zones))
            ->on('POST', '/api/v1/zones', $createResponse
                ?? FakeApi::json(201, ['ok' => true, 'data' => self::zone(900, 'created.test', self::SUB)]));
    }

    private function activity(): string
    {
        return implode("\n", $GLOBALS['__activity_log']);
    }

    // ── Client area: whose domain is it? ───────────────────────────────────────

    public function test_a_domain_not_on_the_clients_account_is_refused(): void
    {
        // victim.com is another WHMCS client's domain (#8).
        self::useDomains([self::domain('victim.com', 'Active', 8)]);
        $api = self::api();

        $out = $this->createZone($api, 'victim.com');

        $this->assertSame([], $api->callsTo('POST', '/api/v1/zones'), 'no zone may be created');
        $this->assertSame('templates/client/zone-create', $out['templatefile']);
        $this->assertSame('error', $out['vars']['flash']['type']);
        $this->assertSame(PanelDnsEmbeddedDnsManager::MSG_NOT_OWNED, $out['vars']['flash']['msg']);
        $this->assertStringContainsString('refused zone victim.com for service #500', $this->activity());

        $lookup = Capsule::ops('get', 'tbldomains')[0];
        $this->assertTrue($lookup->hasWhere('userid', '=', self::CLIENT));
        $this->assertTrue($lookup->hasWhere('domain', 'in', ['victim.com']));
    }

    public function test_a_subdomain_of_another_customers_domain_is_refused(): void
    {
        self::useDomains([self::domain('victim.com', 'Active', 8)]);
        $api = self::api([self::zone(1, 'victim.com', 77)]);

        $out = $this->createZone($api, 'shop.victim.com');

        $this->assertSame([], $api->callsTo('POST', '/api/v1/zones'));
        $this->assertSame(PanelDnsEmbeddedDnsManager::MSG_NOT_OWNED, $out['vars']['flash']['msg']);
    }

    public function test_an_active_domain_on_the_clients_account_is_created(): void
    {
        self::useDomains([self::domain('example.com')]);
        $api = self::api();

        $out = $this->createZone($api, '  Example.COM ');

        $post = $api->callsTo('POST', '/api/v1/zones');
        $this->assertCount(1, $post);
        $this->assertSame(['name' => 'example.com', 'sub_client_id' => self::SUB], $post[0]['body']);
        $this->assertSame('templates/client/zones-list', $out['templatefile']);
        $this->assertSame('success', $out['vars']['flash']['type']);
        // Owned: no overlap lookups are needed (PanelDNS refuses overlaps itself).
        $this->assertSame([], array_filter($api->callsTo('GET', '/api/v1/zones'), fn ($c) => isset($c['query']['name']) || isset($c['query']['search'])));
    }

    public function test_a_domain_whose_transfer_is_pending_is_refused_until_it_completes(): void
    {
        self::useDomains([self::domain('example.com', 'Pending Transfer')]);
        $api = self::api();

        $out = $this->createZone($api, 'example.com');

        $this->assertSame([], $api->callsTo('POST', '/api/v1/zones'));
        $this->assertStringContainsString('has not completed yet', $out['vars']['flash']['msg']);
    }

    /** @dataProvider notOwnedStatusProvider */
    public function test_a_domain_that_is_not_active_is_refused(string $status): void
    {
        self::useDomains([self::domain('example.com', $status)]);
        $api = self::api();

        $out = $this->createZone($api, 'example.com');

        $this->assertSame([], $api->callsTo('POST', '/api/v1/zones'));
        $this->assertSame(PanelDnsEmbeddedDnsManager::MSG_NOT_OWNED, $out['vars']['flash']['msg']);
    }

    public static function notOwnedStatusProvider(): array
    {
        return [
            'ordered, not registered' => ['Pending'],
            'cancelled'               => ['Cancelled'],
            'transferred away'        => ['Transferred Away'],
            'expired'                 => ['Expired'],
            'fraud'                   => ['Fraud'],
        ];
    }

    public function test_the_service_domain_field_is_not_proof_of_ownership(): void
    {
        // ZONE-OWN-05: tblhosting.domain is typed by the customer on the order form.
        self::useDomains([]);
        $api = self::api();

        $out = $this->createZone($api, 'victim.com', ['domain' => 'victim.com']);

        $this->assertSame([], $api->callsTo('POST', '/api/v1/zones'));
        $this->assertSame(PanelDnsEmbeddedDnsManager::MSG_NOT_OWNED, $out['vars']['flash']['msg']);
    }

    public function test_a_failed_domain_lookup_refuses(): void
    {
        Capsule::$handler = function (FakeQuery $q, string $op) {
            if ($q->baseTable() === 'tbldomains') throw new \RuntimeException('SQLSTATE[HY000]: gone away');
            return null;
        };
        $api = self::api();

        $out = $this->createZone($api, 'example.com', ['configoption12' => 'on']);

        $this->assertSame([], $api->callsTo('POST', '/api/v1/zones'));
        $this->assertSame(PanelDnsEmbeddedDnsManager::MSG_CHECK_FAILED, $out['vars']['flash']['msg']);
        $this->assertStringNotContainsString('gone away', $this->activity(), 'exception text stays out of logs');
    }

    // ── Client area: "Allow Zones for Domains Not in This Account" on ──────────

    /** @dataProvider overlapProvider */
    public function test_with_the_option_on_a_name_overlapping_another_customers_zone_is_refused(string $name, array $zones): void
    {
        self::useDomains([]);
        $api = self::api($zones);

        $out = $this->createZone($api, $name, ['configoption12' => 'on']);

        $this->assertSame([], $api->callsTo('POST', '/api/v1/zones'), "{$name} must be refused");
        $this->assertSame(PanelDnsEmbeddedDnsManager::MSG_UNAVAILABLE, $out['vars']['flash']['msg']);
        $this->assertStringContainsString('overlaps zone', $this->activity());
        $this->assertLessThanOrEqual(3, count($api->callsTo('GET', '/api/v1/zones')), 'at most three listing calls');
    }

    public static function overlapProvider(): array
    {
        $foreign = fn (string $n) => [self::zone(1, $n, 77)];
        return [
            'child of another customer'          => ['shop.example.com', $foreign('example.com')],
            'parent of another customer'         => ['example.com', $foreign('shop.example.com')],
            'same name as another customer'      => ['example.com', $foreign('example.com')],
            'deep name, 3-label parent'          => ['a.b.shop.example.com', $foreign('shop.example.com')],
            'deep name, 4-label parent'          => ['a.b.shop.example.com', $foreign('b.shop.example.com')],
            'grandchild of another customer'     => ['example.co.uk', $foreign('x.y.example.co.uk')],
            'under the reseller\'s own org zone' => ['shop.reseller.test', [self::zone(1, 'reseller.test', null)]],
            'a zone for the TLD itself'          => ['example.com', $foreign('com')],
        ];
    }

    public function test_with_the_option_on_unrelated_and_own_zones_do_not_block(): void
    {
        self::useDomains([]);
        $api = self::api([
            self::zone(1, 'myshop.example.com', 77),     // the search finds it; not a parent or child
            self::zone(2, 'shop.example.com.au', 77),    // ditto
            self::zone(3, 'example.com', self::SUB),     // this client's own parent zone
            self::zone(4, 'deep.shop.example.com', self::SUB),
        ]);

        $out = $this->createZone($api, 'shop.example.com', ['configoption12' => 'on']);

        $this->assertCount(1, $api->callsTo('POST', '/api/v1/zones'));
        $this->assertSame('success', $out['vars']['flash']['type']);
    }

    public function test_with_the_option_on_the_overlap_check_follows_every_page(): void
    {
        self::useDomains([]);
        $zones = [];
        for ($i = 1; $i <= 60; $i++) {
            $zones[] = self::zone($i, "z{$i}.example.com", $i === 55 ? 77 : self::SUB);
        }
        $api = self::api($zones);

        $out = $this->createZone($api, 'example.com', ['configoption12' => 'on']);

        $this->assertSame([], $api->callsTo('POST', '/api/v1/zones'), 'the conflict is on page 2');
        $this->assertSame(PanelDnsEmbeddedDnsManager::MSG_UNAVAILABLE, $out['vars']['flash']['msg']);
    }

    public function test_with_the_option_on_a_failed_overlap_lookup_refuses(): void
    {
        self::useDomains([]);
        $api = self::api()->on('GET', '/api/v1/zones', FakeApi::json(500, ['message' => 'Server Error']));

        $out = $this->createZone($api, 'example.com', ['configoption12' => 'on']);

        $this->assertSame([], $api->callsTo('POST', '/api/v1/zones'));
        $this->assertSame(PanelDnsEmbeddedDnsManager::MSG_CHECK_FAILED, $out['vars']['flash']['msg']);
    }

    /** @dataProvider overlapNamesProvider */
    public function test_names_overlap(string $a, string $b, bool $expected): void
    {
        $this->assertSame($expected, PanelDnsEmbeddedDnsManager::namesOverlap($a, $b));
        $this->assertSame($expected, PanelDnsEmbeddedDnsManager::namesOverlap($b, $a));
    }

    public static function overlapNamesProvider(): array
    {
        return [
            'same'                => ['example.com', 'example.com', true],
            'same, case and dot'  => ['Example.COM.', 'example.com', true],
            'child'               => ['shop.example.com', 'example.com', true],
            'deep child'          => ['a.b.c.example.com', 'example.com', true],
            'suffix, not a label' => ['myexample.com', 'example.com', false],
            'prefix only'         => ['example.com.au', 'example.com', false],
            'siblings'            => ['a.example.com', 'b.example.com', false],
        ];
    }

    // ── Client area: what a refused create says ────────────────────────────────

    public function test_a_409_shows_the_generic_message_and_logs_the_detail(): void
    {
        self::useDomains([self::domain('example.com')]);
        $api = self::api([], FakeApi::json(409, ['ok' => false, 'error' => 'Zone already exists.', 'code' => 409]));

        $out = $this->createZone($api, 'example.com');

        $this->assertSame('error', $out['vars']['flash']['type']);
        $this->assertSame('This zone name is unavailable.', $out['vars']['flash']['msg']);
        $this->assertStringNotContainsString('exists', $out['vars']['flash']['msg']);
        $this->assertStringContainsString('PanelDNS HTTP 409: Zone already exists.', $this->activity());
    }

    /** @dataProvider nameConflictProvider */
    public function test_a_422_name_conflict_shows_the_generic_message(array $body): void
    {
        self::useDomains([self::domain('example.com')]);
        $api = self::api([], FakeApi::json(422, $body));

        $out = $this->createZone($api, 'example.com');

        $this->assertSame(PanelDnsEmbeddedDnsManager::MSG_UNAVAILABLE, $out['vars']['flash']['msg']);
    }

    public static function nameConflictProvider(): array
    {
        return [
            'validation error on name' => [['message' => 'The name has already been taken.', 'errors' => ['name' => ['The name has already been taken.']]]],
            'race-path duplicate'      => [['ok' => false, 'error' => "Zone 'example.com' already exists for this organization.", 'code' => 422]],
            'core overlap refusal'     => [['ok' => false, 'error' => 'This zone name is unavailable.', 'code' => 422]],
            'DNS provider duplicate'   => [['ok' => false, 'error' => 'Cloudflare: A zone with this domain already exists (1061)', 'code' => 422]],
        ];
    }

    public function test_a_422_zone_limit_is_still_shown(): void
    {
        self::useDomains([self::domain('example.com')]);
        $api = self::api([], FakeApi::json(422, ['ok' => false, 'error' => 'Sub-client zone limit reached (5/5).', 'code' => 422]));

        $out = $this->createZone($api, 'example.com');

        $this->assertSame('Sub-client zone limit reached (5/5).', $out['vars']['flash']['msg']);
    }

    public function test_the_option_is_configoption12_and_off_by_default(): void
    {
        $opts = paneldnsreseller_ConfigOptions();
        $names = array_keys($opts);
        $this->assertSame('Allow Zones for Domains Not in This Account', $names[11] ?? null, 'must be configoption12');
        $this->assertCount(12, $opts, 'new options go last — WHMCS stores them by position');
        $this->assertSame('yesno', $opts[$names[11]]['Type']);
        $this->assertFalse(PanelDnsResellerHooks::isYes($opts[$names[11]]['Default']));
    }

    // ── Hooks: auto-create only for the client's own, registered or transferred-in domain

    public function test_auto_create_on_registration_creates_the_clients_domain(): void
    {
        self::useDomains([self::domain('example.com', 'Pending')], self::service());
        $api = self::api();
        PanelDnsResellerHooks::$apiFactory = fn () => $api;

        // WHMCS's registrar params: tld given without its dot here, with it below.
        PanelDnsResellerHooks::onDomainRegistered(['params' => ['userid' => self::CLIENT, 'domainid' => 3, 'sld' => 'example', 'tld' => 'com']]);
        PanelDnsResellerHooks::onDomainRegistered(['params' => ['userid' => self::CLIENT, 'domainid' => 3, 'sld' => 'example', 'tld' => '.com']]);

        $post = $api->callsTo('POST', '/api/v1/zones');
        $this->assertCount(2, $post);
        $this->assertSame(['name' => 'example.com', 'sub_client_id' => self::SUB], $post[0]['body']);
        $this->assertSame($post[0]['body'], $post[1]['body']);
    }

    public function test_auto_create_never_creates_a_zone_for_a_domain_not_on_the_account(): void
    {
        self::useDomains([self::domain('victim.com', 'Active', 8)], self::service());
        $api = self::api();
        PanelDnsResellerHooks::$apiFactory = fn () => $api;

        PanelDnsResellerHooks::onDomainEvent(self::CLIENT, 'victim.com', 'create');

        $this->assertSame([], $api->callsTo('POST', '/api/v1/zones'));
        $this->assertStringContainsString('auto-create zone victim.com skipped', $this->activity());
    }

    public function test_auto_create_skips_a_transfer_that_has_not_completed(): void
    {
        self::useDomains([self::domain('example.com', 'Pending Transfer')], self::service());
        $api = self::api();
        PanelDnsResellerHooks::$apiFactory = fn () => $api;

        PanelDnsResellerHooks::onDomainEvent(self::CLIENT, 'example.com', 'create');

        $this->assertSame([], $api->callsTo('POST', '/api/v1/zones'));
    }

    // ── hooks.php: which WHMCS hooks create zones ──────────────────────────────

    /** hooks.php's registrations, loaded once through the stub add_hook() above. */
    private static function hooks(): array
    {
        require_once __DIR__ . '/../modules/servers/paneldnsreseller/hooks.php';
        return $GLOBALS['__hooks'] ?? [];
    }

    private static function fire(string $hook, array $vars): void
    {
        foreach (self::hooks()[$hook] ?? [] as $fn) {
            $fn($vars);
        }
    }

    public function test_submitting_a_transfer_creates_no_zone(): void
    {
        // A transfer order for someone else's domain: WHMCS runs AfterRegistrarTransfer
        // once the command is sent to the registrar, whether or not it succeeded.
        self::useDomains([self::domain('victim.com', 'Pending Transfer')], self::service());
        $api = self::api();
        PanelDnsResellerHooks::$apiFactory = fn () => $api;

        self::fire('AfterRegistrarTransfer', [
            'params' => ['userid' => self::CLIENT, 'domainid' => 3, 'sld' => 'victim', 'tld' => 'com', 'domainname' => 'victim.com'],
            'results' => ['success' => true], 'functionExists' => true, 'functionSuccessful' => true,
        ]);

        $this->assertSame([], $api->callsTo('POST', '/api/v1/zones'));
        $this->assertArrayNotHasKey('AfterRegistrarTransfer', self::hooks());
    }

    public function test_a_completed_transfer_creates_the_zone_for_the_domains_owner(): void
    {
        // The sync cron may fire the hook before or after it flips the status to Active.
        self::useDomains([self::domain('example.com', 'Pending Transfer')], self::service());
        $api = self::api();
        PanelDnsResellerHooks::$apiFactory = fn () => $api;

        self::fire('DomainTransferCompleted', [
            'domainId' => 3, 'domain' => 'example.com', 'registrationPeriod' => '1',
            'expiryDate' => '2027-11-18', 'registrar' => 'enom',
        ]);

        $post = $api->callsTo('POST', '/api/v1/zones');
        $this->assertCount(1, $post);
        $this->assertSame(['name' => 'example.com', 'sub_client_id' => self::SUB], $post[0]['body']);
        $this->assertTrue(Capsule::ops('first', 'tbldomains')[0]->hasWhere('id', '=', 3));
    }

    public function test_a_completed_transfer_of_a_cancelled_domain_creates_no_zone(): void
    {
        self::useDomains([self::domain('example.com', 'Cancelled')], self::service());
        $api = self::api();
        PanelDnsResellerHooks::$apiFactory = fn () => $api;

        self::fire('DomainTransferCompleted', ['domainId' => 3, 'domain' => 'example.com']);

        $this->assertSame([], $api->callsTo('POST', '/api/v1/zones'));
    }

    public function test_registration_and_completed_transfer_are_the_only_auto_create_hooks(): void
    {
        $hooks = self::hooks();
        $this->assertArrayHasKey('AfterRegistrarRegistration', $hooks);
        $this->assertArrayHasKey('DomainTransferCompleted', $hooks);
        $this->assertArrayNotHasKey('AfterRegistrarTransfer', $hooks);
    }
}
