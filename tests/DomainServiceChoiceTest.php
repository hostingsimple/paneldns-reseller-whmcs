<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/**
 * DOMAIN-SVC-01 (v1.9.6): when a client has several DNS services, domain auto-create and
 * auto-delete act on the one ordered together with the domain, or on none (logged), with
 * the same rule for both. Only services with the relevant option ticked are candidates.
 *
 * Up to 1.9.5 the hook took ->first() of the client's Active services with no ordering
 * and no server filter, so with DNS services on two PanelDNS servers or reseller orgs the
 * zone landed under whichever row MySQL returned, and auto-delete could look in the
 * wrong sub-client.
 *
 * @covers \PanelDnsResellerHooks
 */
final class DomainServiceChoiceTest extends TestCase
{
    private const CLIENT = 7;

    /** @var array<int,FakeApi> sub-client id => API of its server */
    private array $apis = [];

    protected function setUp(): void
    {
        Capsule::reset();
        PanelDnsResellerApi::resetRateLimitState();
        $GLOBALS['__activity_log'] = [];
        $this->apis = [];
    }

    protected function tearDown(): void
    {
        Capsule::reset();
        PanelDnsResellerHooks::$apiFactory = null;
    }

    private static function service(int $id, int $orderId, int $subClientId, string $autoCreate = 'on', string $autoDelete = 'on'): object
    {
        return (object) [
            'service_id' => $id, 'order_id' => $orderId, 'sub_client_id' => (string) $subClientId,
            'server_hostname' => "dns{$subClientId}.test", 'server_key' => 'k', 'server_secure' => 1, 'server_port' => 443,
            'auto_create' => $autoCreate, 'auto_delete' => $autoDelete,
        ];
    }

    /**
     * The client's services (MySQL may return them in any order; first() gets the first),
     * and example.com, Active, ordered in $domainOrderId. Each sub-client has its own API.
     */
    private function world(array $services, int $domainOrderId): void
    {
        foreach ($services as $s) {
            $sub = (int) $s->sub_client_id;
            $this->apis[$sub] = (new FakeApi())
                ->on('POST', '/api/v1/zones', FakeApi::json(201, ['ok' => true, 'data' => ['id' => 900, 'name' => 'example.com', 'sub_client_id' => $sub]]))
                ->on('GET', '/api/v1/zones', FakeApi::json(200, ['ok' => true, 'data' => [
                    ['id' => 900 + $sub, 'name' => 'example.com', 'sub_client_id' => $sub],
                ], 'meta' => ['page' => 1, 'per_page' => 50, 'total' => 1]]))
                ->on('DELETE', '/api/v1/zones/' . (900 + $sub), FakeApi::json(200, ['ok' => true, 'data' => null]));
        }
        $apis = $this->apis;
        PanelDnsResellerHooks::$apiFactory = fn (object $row) => $apis[(int) $row->sub_client_id];

        $domain = (object) ['id' => 3, 'userid' => self::CLIENT, 'domain' => 'example.com', 'status' => 'Active', 'orderid' => $domainOrderId];
        Capsule::$handler = function (FakeQuery $q, string $op) use ($services, $domain) {
            if ($q->baseTable() === 'tblhosting') {
                return match ($op) { 'get' => $services, 'first' => $services[0] ?? null, default => null };
            }
            if ($q->baseTable() === 'tbldomains') {
                return match ($op) { 'get' => [$domain], 'first' => $domain, 'value' => $domain->{$q->arg} ?? null, default => null };
            }
            return null;
        };
    }

    /** @return int[] sub-client ids that got a zone created */
    private function createdUnder(): array
    {
        $subs = [];
        foreach ($this->apis as $sub => $api) {
            foreach ($api->callsTo('POST', '/api/v1/zones') as $call) {
                $subs[] = (int) $call['body']['sub_client_id'];
            }
        }
        return $subs;
    }

    public function test_the_service_ordered_with_the_domain_gets_the_zone(): void
    {
        // Service #500 (sub-client 42, order 10) comes back first; the domain was ordered
        // with service #501 (sub-client 43, order 11).
        $this->world([self::service(500, 10, 42), self::service(501, 11, 43)], 11);

        PanelDnsResellerHooks::onDomainEvent(self::CLIENT, 'example.com', 'create');

        $this->assertSame([43], $this->createdUnder());
    }

    public function test_several_services_and_no_order_link_create_nothing_and_log(): void
    {
        $this->world([self::service(500, 10, 42), self::service(501, 11, 43)], 12);

        PanelDnsResellerHooks::onDomainEvent(self::CLIENT, 'example.com', 'create');

        $this->assertSame([], $this->createdUnder());
        $this->assertStringContainsString('several DNS services (#500, #501)', implode("\n", $GLOBALS['__activity_log']));
    }

    public function test_only_services_with_auto_create_on_are_candidates(): void
    {
        // #500 comes back first but has auto-create off; #501 is the only candidate.
        $this->world([self::service(500, 10, 42, ''), self::service(501, 11, 43)], 12);

        PanelDnsResellerHooks::onDomainEvent(self::CLIENT, 'example.com', 'create');

        $this->assertSame([43], $this->createdUnder());
    }

    public function test_delete_uses_the_same_rule(): void
    {
        $this->world([self::service(500, 10, 42), self::service(501, 11, 43)], 11);

        PanelDnsResellerHooks::onDomainEvent(self::CLIENT, 'example.com', 'delete');

        $this->assertSame([], $this->apis[42]->calls, 'the other sub-client is not even searched');
        $this->assertCount(1, $this->apis[43]->callsTo('DELETE', '/api/v1/zones/943'));
    }

    public function test_delete_with_no_order_link_deletes_nothing(): void
    {
        $this->world([self::service(500, 10, 42), self::service(501, 11, 43)], 0);

        PanelDnsResellerHooks::onDomainEvent(self::CLIENT, 'example.com', 'delete');

        $this->assertSame([], $this->apis[42]->calls);
        $this->assertSame([], $this->apis[43]->calls);
    }

    public function test_a_single_service_is_used_as_before(): void
    {
        $this->world([self::service(500, 10, 42)], 99);

        PanelDnsResellerHooks::onDomainEvent(self::CLIENT, 'example.com', 'create');

        $this->assertSame([42], $this->createdUnder());
        $this->assertSame([], array_filter(Capsule::$queries, fn (FakeQuery $q) => $q->op === 'value'), 'no order lookup for one service');
    }
}
