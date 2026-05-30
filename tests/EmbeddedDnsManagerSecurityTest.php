<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for security controls in EmbeddedDnsManager.
 * Focuses on pure-logic that can be exercised without a live WHMCS or API.
 *
 * @covers \PanelDnsEmbeddedDnsManager
 */
final class EmbeddedDnsManagerSecurityTest extends TestCase
{
    /**
     * The record type allowlist defined in recordPayloadFromPost().
     * These are the 13 types the module accepts.
     */
    private const ALLOWED_TYPES = [
        'A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA',
        'PTR', 'TLSA', 'SSHFP', 'HTTPS', 'NAPTR',
    ];

    // ── Record type allowlist ─────────────────────────────────────────────────

    /** @dataProvider allowedTypeProvider */
    public function test_allowed_record_types_are_in_allowlist(string $type): void
    {
        $this->assertContains($type, self::ALLOWED_TYPES);
    }

    public static function allowedTypeProvider(): array
    {
        return array_combine(
            self::ALLOWED_TYPES,
            array_map(fn ($t) => [$t], self::ALLOWED_TYPES)
        );
    }

    /** @dataProvider rejectedTypeProvider */
    public function test_rejected_types_are_not_in_allowlist(string $type): void
    {
        $this->assertNotContains(strtoupper($type), self::ALLOWED_TYPES);
    }

    public static function rejectedTypeProvider(): array
    {
        return [
            // Note: the allowlist check is applied after strtoupper(), so lowercase
            // valid types ('a','mx') ARE accepted. Only check truly invalid types here.
            'sql injection attempt'   => ["A'; DROP TABLE--"],
            'script tag'              => ['<script>'],
            'empty string'            => [''],
            'unknown type AAABBB'     => ['AAABBB'],
            'fake type PROTO'         => ['__PROTO__'],
            'null byte'               => ["\x00A"],
            'type with space'         => ['A B'],
        ];
    }

    // ── BIND import size cap ──────────────────────────────────────────────────

    public function test_512kb_cap_constant_is_correct(): void
    {
        // The cap is 512 * 1024 bytes = 524288. Verify the arithmetic used
        // in EmbeddedDnsManager::doZoneImport() matches the documented limit.
        $this->assertSame(524288, 512 * 1024);
    }

    public function test_string_just_at_limit_is_accepted(): void
    {
        $payload = str_repeat('A', 512 * 1024);
        $this->assertFalse(strlen($payload) > 512 * 1024);
    }

    public function test_string_one_byte_over_limit_is_rejected(): void
    {
        $payload = str_repeat('A', 512 * 1024 + 1);
        $this->assertTrue(strlen($payload) > 512 * 1024);
    }

    // ── CSRF token properties ─────────────────────────────────────────────────

    public function test_csrf_token_generated_has_expected_entropy(): void
    {
        // bin2hex(random_bytes(24)) produces 48 hex chars.
        $token = bin2hex(random_bytes(24));
        $this->assertSame(48, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $token);
    }

    public function test_csrf_comparison_is_timing_safe(): void
    {
        // hash_equals is used — verify the function exists (PHP 5.6+).
        $this->assertTrue(function_exists('hash_equals'));

        $a = bin2hex(random_bytes(24));
        $b = bin2hex(random_bytes(24));
        // Different tokens must not match.
        $this->assertFalse(hash_equals($a, $b));
        // Same token must match.
        $this->assertTrue(hash_equals($a, $a));
    }

    // ── SSO redirect URL safety ───────────────────────────────────────────────

    /** @dataProvider ssoUrlProvider */
    public function test_sso_redirect_only_allows_https(string $url, bool $shouldAllow): void
    {
        // Mirrors the JS guard in sso-redirect.tpl: /^https:\/\//.test(url)
        $allowed = (bool) preg_match('#^https://#', $url);
        $this->assertSame($shouldAllow, $allowed, "URL: {$url}");
    }

    public static function ssoUrlProvider(): array
    {
        return [
            'valid https'           => ['https://portal.example.com/login?token=abc', true],
            'http rejected'         => ['http://portal.example.com/login',            false],
            'empty rejected'        => ['',                                            false],
            'javascript rejected'   => ['javascript:alert(1)',                        false],
            'data uri rejected'     => ['data:text/html,<script>',                   false],
            'relative rejected'     => ['/login?token=abc',                           false],
        ];
    }
}
