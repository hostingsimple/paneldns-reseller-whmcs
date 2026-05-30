<?php

use PHPUnit\Framework\TestCase;

/**
 * @covers \PanelDnsLicenceCheck
 */
final class LicenceCheckTest extends TestCase
{
    private const NOW = 1700000000;

    public function test_active_subscription_unlocks_module(): void
    {
        $r = PanelDnsLicenceCheck::interpret([
            'fetched_at' => self::NOW,
            'sub_status' => 'active',
            'modules'    => ['whmcs-platform', 'whmcs-reseller'],
            'expires_at' => null,
        ], PanelDnsLicenceCheck::REQUIRED_MODULE_RESELLER, self::NOW);

        $this->assertTrue($r['unlocked']);
        $this->assertStringContainsString('active', $r['reason']);
    }

    public function test_trialing_subscription_unlocks_module(): void
    {
        $r = PanelDnsLicenceCheck::interpret([
            'fetched_at' => self::NOW,
            'sub_status' => 'trialing',
            'modules'    => ['whmcs-platform', 'whmcs-reseller'],
            'expires_at' => '2026-12-31T00:00:00Z',
        ], PanelDnsLicenceCheck::REQUIRED_MODULE_RESELLER, self::NOW);

        $this->assertTrue($r['unlocked']);
        $this->assertSame('trialing', $r['sub_status']);
    }

    public function test_past_due_within_grace_unlocks(): void
    {
        $r = PanelDnsLicenceCheck::interpret([
            'fetched_at' => self::NOW - 86400, // 1 day past due
            'sub_status' => 'past_due',
            'modules'    => ['whmcs-platform', 'whmcs-reseller'],
            'expires_at' => null,
        ], PanelDnsLicenceCheck::REQUIRED_MODULE_RESELLER, self::NOW);

        $this->assertTrue($r['unlocked']);
        $this->assertStringContainsString('grace', $r['reason']);
        $this->assertStringContainsString('6 day(s)', $r['reason']); // 7 - 1 = 6
    }

    public function test_past_due_past_grace_locks(): void
    {
        $r = PanelDnsLicenceCheck::interpret([
            'fetched_at' => self::NOW - (8 * 86400), // 8 days past due, grace = 7
            'sub_status' => 'past_due',
            'modules'    => ['whmcs-platform', 'whmcs-reseller'],
            'expires_at' => null,
        ], PanelDnsLicenceCheck::REQUIRED_MODULE_RESELLER, self::NOW);

        $this->assertFalse($r['unlocked']);
        $this->assertStringContainsString('grace period expired', $r['reason']);
    }

    public function test_cancelled_locks(): void
    {
        $r = PanelDnsLicenceCheck::interpret([
            'fetched_at' => self::NOW,
            'sub_status' => 'cancelled',
            'modules'    => [],
            'expires_at' => null,
        ], PanelDnsLicenceCheck::REQUIRED_MODULE_RESELLER, self::NOW);

        $this->assertFalse($r['unlocked']);
    }

    public function test_free_subscription_locks(): void
    {
        $r = PanelDnsLicenceCheck::interpret([
            'fetched_at' => self::NOW,
            'sub_status' => 'free',
            'modules'    => [],
            'expires_at' => null,
        ], PanelDnsLicenceCheck::REQUIRED_MODULE_RESELLER, self::NOW);

        $this->assertFalse($r['unlocked']);
        $this->assertSame('free', $r['sub_status']);
    }

    public function test_active_but_module_not_unlocked_locks(): void
    {
        // Active sub but the API forgot to include 'whmcs-reseller' in modules_unlocked.
        // Should lock — defence in depth.
        $r = PanelDnsLicenceCheck::interpret([
            'fetched_at' => self::NOW,
            'sub_status' => 'active',
            'modules'    => ['whmcs-platform'],   // reseller MISSING
            'expires_at' => null,
        ], PanelDnsLicenceCheck::REQUIRED_MODULE_RESELLER, self::NOW);

        $this->assertFalse($r['unlocked']);
        $this->assertStringContainsString('module not unlocked', $r['reason']);
    }

    public function test_unknown_status_locks(): void
    {
        $r = PanelDnsLicenceCheck::interpret([
            'fetched_at' => self::NOW,
            'sub_status' => 'unknown',
            'modules'    => [],
            'expires_at' => null,
        ], PanelDnsLicenceCheck::REQUIRED_MODULE_RESELLER, self::NOW);

        $this->assertFalse($r['unlocked']);
    }

    public function test_grace_boundary_exact_seven_days_locks(): void
    {
        // 7 days * 86400 = 604800. Anything >= 604800 is past grace.
        $r = PanelDnsLicenceCheck::interpret([
            'fetched_at' => self::NOW - (7 * 86400),
            'sub_status' => 'past_due',
            'modules'    => ['whmcs-platform', 'whmcs-reseller'],
            'expires_at' => null,
        ], PanelDnsLicenceCheck::REQUIRED_MODULE_RESELLER, self::NOW);

        $this->assertFalse($r['unlocked']);
    }

    // ── T1.3 error banner formatting ─────────────────────────────────────────

    public function test_formatErrorBanner_cancelled(): void
    {
        $banner = PanelDnsLicenceCheck::formatErrorBanner([
            'unlocked'   => false,
            'sub_status' => 'cancelled',
            'expires_at' => '2026-05-20T00:00:00Z',
            'reason'     => 'whatever',
        ]);

        $this->assertStringContainsString('⚠ PanelDNS subscription cancelled', $banner);
        $this->assertStringContainsString('New provisioning is disabled', $banner);
        $this->assertStringContainsString('Subscription expired: 2026-05-20', $banner);
        $this->assertStringContainsString('Reactivate at:', $banner);
    }

    public function test_formatErrorBanner_past_due(): void
    {
        $banner = PanelDnsLicenceCheck::formatErrorBanner([
            'unlocked'   => false,
            'sub_status' => 'past_due',
            'expires_at' => null,
            'reason'     => 'grace expired',
        ]);

        $this->assertStringContainsString('past due', $banner);
        $this->assertStringContainsString('7-day grace period', $banner);
    }

    public function test_formatErrorBanner_free(): void
    {
        $banner = PanelDnsLicenceCheck::formatErrorBanner([
            'unlocked'   => false,
            'sub_status' => 'free',
            'expires_at' => null,
            'reason'     => 'module not unlocked',
        ]);

        $this->assertStringContainsString('No active PanelDNS subscription', $banner);
        $this->assertStringContainsString('requires an active PanelDNS subscription', $banner);
    }

    public function test_formatErrorBanner_unknown(): void
    {
        $banner = PanelDnsLicenceCheck::formatErrorBanner([
            'unlocked'   => false,
            'sub_status' => 'unknown',
            'expires_at' => null,
            'reason'     => 'cannot reach server',
        ]);

        $this->assertStringContainsString('Could not verify', $banner);
        $this->assertStringContainsString('server hostname and access hash', $banner);
    }

    public function test_formatErrorBanner_falls_back_when_no_reactivation_url(): void
    {
        $banner = PanelDnsLicenceCheck::formatErrorBanner([
            'unlocked'   => false,
            'sub_status' => 'cancelled',
            'expires_at' => null,
            'reason'     => 'x',
        ]);

        // When tbladdonmodules has no entry (in tests it doesn't), banner
        // falls back to a guidance hint, not a blank URL.
        $this->assertStringContainsString('Setup → Addon Modules', $banner);
    }
}
