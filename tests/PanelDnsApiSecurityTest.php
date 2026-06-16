<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for security-sensitive private methods on PanelDnsResellerApi.
 * Uses Reflection so the production class stays clean (private visibility).
 *
 * @covers \PanelDnsResellerApi
 */
final class PanelDnsApiSecurityTest extends TestCase
{
    // ── isPrivateIp ───────────────────────────────────────────────────────────

    /** @dataProvider privateIpProvider */
    public function test_isPrivateIp_blocks_private_ranges(string $ip): void
    {
        $this->assertTrue($this->callIsPrivateIp($ip), "Expected {$ip} to be blocked");
    }

    public static function privateIpProvider(): array
    {
        return [
            'loopback'              => ['127.0.0.1'],
            'loopback-high'         => ['127.255.255.255'],
            'rfc1918-10'            => ['10.0.0.1'],
            'rfc1918-10-high'       => ['10.255.255.255'],
            'rfc1918-172-16'        => ['172.16.0.1'],
            'rfc1918-172-31'        => ['172.31.255.255'],
            'rfc1918-192-168'       => ['192.168.0.1'],
            'rfc1918-192-168-high'  => ['192.168.255.255'],
            'link-local'            => ['169.254.0.1'],
            'link-local-high'       => ['169.254.255.255'],
            'this-network'          => ['0.0.0.1'],
            'shared-cgnat'          => ['100.64.0.1'],
            'shared-cgnat-high'     => ['100.127.255.255'],
            'unparseable'           => ['not-an-ip'],
            'empty'                 => [''],
        ];
    }

    /** @dataProvider publicIpProvider */
    public function test_isPrivateIp_allows_public_ranges(string $ip): void
    {
        $this->assertFalse($this->callIsPrivateIp($ip), "Expected {$ip} to be allowed");
    }

    public static function publicIpProvider(): array
    {
        return [
            'cloudflare-dns'    => ['1.1.1.1'],
            'google-dns'        => ['8.8.8.8'],
            'typical-server-a'  => ['185.100.200.50'],
            'typical-server-b'  => ['45.33.32.156'],
            '172-non-private'   => ['172.15.255.255'],  // just outside 172.16.0.0/12
            '172-non-private-2' => ['172.32.0.1'],      // just outside 172.16.0.0/12
        ];
    }

    // ── redactUrl ─────────────────────────────────────────────────────────────

    /** @dataProvider redactUrlProvider */
    public function test_redactUrl_strips_sensitive_params(string $input, string $expected): void
    {
        $result = $this->callRedactUrl($input);
        $this->assertSame($expected, $result);
    }

    public static function redactUrlProvider(): array
    {
        return [
            'search param stripped' => [
                'https://example.com/api/v1/sub-clients?search=admin%40example.com&page=1',
                'https://example.com/api/v1/sub-clients?search=[REDACTED]&page=1',
            ],
            'email param stripped' => [
                'https://example.com/api?email=test@test.com',
                'https://example.com/api?email=[REDACTED]',
            ],
            'token param stripped' => [
                'https://example.com/api?token=abc123&other=fine',
                'https://example.com/api?token=[REDACTED]&other=fine',
            ],
            'password param stripped' => [
                'https://example.com/api?password=secret',
                'https://example.com/api?password=[REDACTED]',
            ],
            'key param stripped' => [
                'https://example.com/api?key=abc&id=1',
                'https://example.com/api?key=[REDACTED]&id=1',
            ],
            'secret param stripped' => [
                'https://example.com/api?secret=xyz',
                'https://example.com/api?secret=[REDACTED]',
            ],
            'clean url untouched' => [
                'https://example.com/api/v1/sub-clients?page=2&per_page=50',
                'https://example.com/api/v1/sub-clients?page=2&per_page=50',
            ],
            'case insensitive' => [
                'https://example.com/api?SEARCH=foo&TOKEN=bar',
                'https://example.com/api?SEARCH=[REDACTED]&TOKEN=[REDACTED]',
            ],
            'multiple sensitive params' => [
                'https://example.com/api?token=x&key=y&id=42',
                'https://example.com/api?token=[REDACTED]&key=[REDACTED]&id=42',
            ],
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function callIsPrivateIp(string $ip): bool
    {
        $ref = new ReflectionMethod(PanelDnsResellerApi::class, 'isPrivateIp');
        $ref->setAccessible(true);
        return $ref->invoke(null, $ip);
    }

    private function callRedactUrl(string $url): string
    {
        $api = new PanelDnsResellerApi('https://example.com', 'key', PanelDnsResellerApi::MODE_RESELLER);
        $ref = new ReflectionMethod(PanelDnsResellerApi::class, 'redactUrl');
        $ref->setAccessible(true);
        return $ref->invoke($api, $url);
    }
}
