<?php

use PHPUnit\Framework\TestCase;

/**
 * @covers \PanelDnsResellerApi
 */
final class PanelDnsApiTest extends TestCase
{
    public function test_prefix_returns_platform_path_for_platform_mode(): void
    {
        $api = new PanelDnsResellerApi('https://example.com', 'k', PanelDnsResellerApi::MODE_PLATFORM);
        $this->assertSame('/platform/v1', $api->prefix());
    }

    public function test_prefix_returns_api_path_for_reseller_mode(): void
    {
        $api = new PanelDnsResellerApi('https://example.com', 'k', PanelDnsResellerApi::MODE_RESELLER);
        $this->assertSame('/api/v1', $api->prefix());
    }

    public function test_mode_defaults_to_reseller_when_garbage_given(): void
    {
        $api = new PanelDnsResellerApi('https://example.com', 'k', 'not-a-mode');
        $this->assertSame('/api/v1', $api->prefix());
    }

    public function test_identity_hash_is_stable_across_instances(): void
    {
        $a = new PanelDnsResellerApi('https://example.com', 'thekey', PanelDnsResellerApi::MODE_PLATFORM);
        $b = new PanelDnsResellerApi('https://example.com', 'thekey', PanelDnsResellerApi::MODE_PLATFORM);
        $this->assertSame($a->identityHash(), $b->identityHash());
    }

    public function test_identity_hash_changes_when_key_changes(): void
    {
        $a = new PanelDnsResellerApi('https://example.com', 'keyA', PanelDnsResellerApi::MODE_PLATFORM);
        $b = new PanelDnsResellerApi('https://example.com', 'keyB', PanelDnsResellerApi::MODE_PLATFORM);
        $this->assertNotSame($a->identityHash(), $b->identityHash());
    }

    public function test_identity_hash_changes_when_mode_changes(): void
    {
        $a = new PanelDnsResellerApi('https://example.com', 'k', PanelDnsResellerApi::MODE_PLATFORM);
        $b = new PanelDnsResellerApi('https://example.com', 'k', PanelDnsResellerApi::MODE_RESELLER);
        $this->assertNotSame($a->identityHash(), $b->identityHash());
    }

    public function test_identity_hash_changes_when_host_changes(): void
    {
        $a = new PanelDnsResellerApi('https://a.example.com', 'k', PanelDnsResellerApi::MODE_PLATFORM);
        $b = new PanelDnsResellerApi('https://b.example.com', 'k', PanelDnsResellerApi::MODE_PLATFORM);
        $this->assertNotSame($a->identityHash(), $b->identityHash());
    }

    public function test_identity_hash_does_not_leak_raw_key(): void
    {
        $key = 'this-is-a-secret-' . str_repeat('x', 40);
        $api = new PanelDnsResellerApi('https://example.com', $key, PanelDnsResellerApi::MODE_PLATFORM);
        $hash = $api->identityHash();

        $this->assertSame(16, strlen($hash));
        $this->assertStringNotContainsString($key, $hash);
        $this->assertStringNotContainsString(substr($key, 0, 8), $hash);
    }

    public function test_base_url_normalisation_strips_trailing_slash(): void
    {
        // We can't directly inspect baseUrl since it's private, but identityHash
        // is derived from it — two URLs that differ only by trailing slash
        // should hash identically.
        $a = new PanelDnsResellerApi('https://example.com', 'k', PanelDnsResellerApi::MODE_PLATFORM);
        $b = new PanelDnsResellerApi('https://example.com/', 'k', PanelDnsResellerApi::MODE_PLATFORM);
        $this->assertSame($a->identityHash(), $b->identityHash());
    }
}
