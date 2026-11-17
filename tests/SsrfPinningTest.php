<?php

use PHPUnit\Framework\TestCase;

/**
 * SSRF-PIN-01 (v1.9.6): the API client resolves the PanelDNS host once, refuses a private
 * or reserved address BEFORE anything is sent, and pins cURL to the checked address
 * (CURLOPT_RESOLVE), so a second DNS answer cannot re-point the request.
 *
 * Up to 1.9.5 the callers ran gethostbyname() and cURL then resolved the name again: a
 * short-TTL record could pass the check and then point the request, with its Bearer
 * token, at an internal address. The private-IP check inside request() looked at
 * CURLINFO_PRIMARY_IP, i.e. after the request had been sent.
 *
 * @covers \PanelDnsResellerApi
 */
final class SsrfPinningTest extends TestCase
{
    protected function setUp(): void
    {
        PanelDnsResellerApi::resetRateLimitState();
        $GLOBALS['__module_log'] = [];
    }

    /**
     * A client whose resolver answers from $answers (one list per lookup, the last one
     * repeating) and whose network records what would be sent.
     */
    private static function client(array $answers, string $baseUrl = 'https://paneldns.example'): PanelDnsResellerApi
    {
        return new class($baseUrl, $answers) extends PanelDnsResellerApi {
            public array $sent = [];
            public array $lookups = [];
            private array $answers;

            public function __construct(string $baseUrl, array $answers)
            {
                parent::__construct($baseUrl, 'secret-token', self::MODE_RESELLER);
                $this->answers = $answers;
            }

            protected function resolveHost(string $host): array
            {
                $this->lookups[] = $host;
                return count($this->answers) > 1 ? array_shift($this->answers) : ($this->answers[0] ?? []);
            }

            protected function transport(string $method, string $url, ?array $body): array
            {
                $this->sent[] = ['url' => $url, 'pin' => $this->pin];
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $page = max(1, (int) ($q['page'] ?? 1));
                return [
                    'status' => 200, 'body' => json_encode(['ok' => true, 'data' => array_fill(0, 50, ['id' => 1]),
                        'meta' => ['page' => $page, 'per_page' => 50, 'total' => 120]]),
                    'headers' => [], 'curl_error' => '', 'curl_errno' => 0, 'primary_ip' => $this->pin['ip'] ?? '',
                ];
            }
        };
    }

    /** @dataProvider privateAddressProvider */
    public function test_a_host_resolving_to_a_private_address_is_refused_before_sending(string $ip): void
    {
        $api = self::client([[$ip]]);

        $r = $api->summary();

        $this->assertFalse($r['ok']);
        $this->assertSame(0, $r['status']);
        $this->assertSame('SSRF guard: target resolved to a private IP address', $r['error']);
        $this->assertSame([], $api->sent, 'nothing — not even the Bearer token — may be sent');
    }

    public static function privateAddressProvider(): array
    {
        return [
            'RFC 1918 10/8'      => ['10.0.0.5'],
            'RFC 1918 172.16/12' => ['172.20.1.1'],
            'RFC 1918 192.168'   => ['192.168.1.10'],
            'loopback'           => ['127.0.0.1'],
            'cloud metadata'     => ['169.254.169.254'],
            'CGNAT 100.64/10'    => ['100.64.0.1'],
            'this network'       => ['0.0.0.0'],
            'reserved 240/4'     => ['240.0.0.1'],
        ];
    }

    public function test_the_checked_address_is_pinned_for_the_request(): void
    {
        $api = self::client([['203.0.113.10']]);

        $this->assertTrue($api->summary()['ok']);

        $this->assertSame(['host' => 'paneldns.example', 'port' => 443, 'ip' => '203.0.113.10'], $api->sent[0]['pin']);
        $this->assertSame('paneldns.example:443:203.0.113.10', PanelDnsResellerApi::curlResolveEntry($api->sent[0]['pin']));
    }

    public function test_a_rebinding_answer_cannot_re_point_later_requests(): void
    {
        // First answer public, every later answer internal (a short-TTL rebinding record).
        $api = self::client([['203.0.113.10'], ['10.0.0.5']]);

        $api->getAllPages('/api/v1/zones');   // three pages
        $api->summary();

        $this->assertCount(4, $api->sent);
        $this->assertSame(['paneldns.example'], $api->lookups, 'resolved once per client');
        foreach ($api->sent as $call) {
            $this->assertSame('203.0.113.10', $call['pin']['ip']);
        }
    }

    public function test_an_unresolvable_host_sends_nothing(): void
    {
        $api = self::client([[]]);

        $r = $api->summary();

        $this->assertFalse($r['ok']);
        $this->assertSame(0, $r['status']);
        $this->assertSame([], $api->sent);
    }

    public function test_an_ip_address_url_is_checked_without_a_lookup(): void
    {
        $private = self::client([['203.0.113.10']], 'https://10.1.2.3');
        $this->assertFalse($private->summary()['ok']);
        $this->assertSame([], $private->sent);

        $public = self::client([['10.0.0.5']], 'https://203.0.113.20:8443');
        $this->assertTrue($public->summary()['ok']);
        $this->assertSame([], $public->lookups);
        $this->assertSame(['host' => '203.0.113.20', 'port' => 8443, 'ip' => '203.0.113.20'], $public->sent[0]['pin']);
        $this->assertNull(PanelDnsResellerApi::curlResolveEntry($public->sent[0]['pin']), 'no CURLOPT_RESOLVE for an IP URL');
    }

    public function test_the_real_transport_hands_the_pin_to_curl(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../shared/PanelDnsApi.php');
        $this->assertMatchesRegularExpression('/\$opts\[CURLOPT_RESOLVE\]\s*=\s*\[\$resolve\]/', $src);
        $this->assertMatchesRegularExpression('/\$target = \$this->pinTarget\(\$url\);.*?\$res = \$this->transport\(/s', $src,
            'the target is checked before transport() runs');
    }
}
