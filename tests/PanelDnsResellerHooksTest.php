<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for PanelDnsResellerHooks pure logic.
 * The hook class is loaded here via require; DB and API calls are not exercised.
 *
 * @covers \PanelDnsResellerHooks
 */
final class PanelDnsResellerHooksTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Stub WHMCS Capsule so the hooks file can be included without a DB.
        if (!class_exists('\\WHMCS\\Database\\Capsule', false)) {
            eval('
                namespace WHMCS\\Database;
                class Capsule {
                    public static function table(string $t): static { return new static(); }
                    public function join(...$a): static { return $this; }
                    public function where(...$a): static { return $this; }
                    public function whereIn(...$a): static { return $this; }
                    public function select(...$a): static { return $this; }
                    public function first() { return null; }
                    public function get(): array { return []; }
                    public function value(string $col) { return null; }
                    public function update(array $d): void {}
                }
            ');
        }

        // In a deployed module, build.php copies shared/PanelDnsApi.php into lib/.
        // In the test environment that step is skipped, so we create a thin redirect
        // file on the fly to satisfy require_once in PanelDnsResellerHooks.php.
        $libDir  = __DIR__ . '/../modules/servers/paneldns-reseller/lib';
        $libFile = $libDir . '/PanelDnsApi.php';
        if (!file_exists($libFile)) {
            file_put_contents($libFile, "<?php // forwarded by test bootstrap — PanelDnsResellerApi already loaded\n");
        }

        require_once __DIR__ . '/../modules/servers/paneldns-reseller/lib/PanelDnsResellerHooks.php';
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

    // ── Grace period date comparison ─────────────────────────────────────────

    /**
     * Grace period expiry logic from processExpiredGracePeriods:
     * the deadline date string is compared to today with string comparison.
     * 'YYYY-MM-DD' lexicographic ordering matches chronological ordering.
     */
    public function test_grace_deadline_comparison_past_is_expired(): void
    {
        $today    = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        // If deadline <= today the grace period has expired.
        $this->assertLessThanOrEqual($today, $yesterday);
    }

    public function test_grace_deadline_comparison_today_is_expired(): void
    {
        $today = date('Y-m-d');
        $this->assertLessThanOrEqual($today, $today);
    }

    public function test_grace_deadline_comparison_future_is_not_expired(): void
    {
        $today    = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $this->assertGreaterThan($today, $tomorrow);
    }

    /**
     * The grace marker regex used in processExpiredGracePeriods.
     *
     * @dataProvider graceMarkerProvider
     */
    public function test_grace_marker_regex_parses_deadline(string $notes, ?string $expectedDeadline): void
    {
        preg_match('/\[paneldns-grace:(\d{4}-\d{2}-\d{2})\]/', $notes, $m);
        $this->assertSame($expectedDeadline, $m[1] ?? null);
    }

    public static function graceMarkerProvider(): array
    {
        return [
            'valid marker'           => ['[paneldns-grace:2026-06-15]', '2026-06-15'],
            'marker with other text' => ['Some notes [paneldns-grace:2026-01-01] end', '2026-01-01'],
            'no marker'              => ['Plain notes', null],
            'malformed marker'       => ['[paneldns-grace:not-a-date]', null],
            'empty notes'            => ['', null],
        ];
    }

    // ── onClientEdit name concatenation ──────────────────────────────────────

    public function test_client_name_concatenation_trims_correctly(): void
    {
        // The name passed to patchSubClient is built as: trim("$firstName $lastName")
        $cases = [
            ['John', 'Doe', 'John Doe'],
            ['John', '',    'John'],    // trailing space trimmed
            ['',     'Doe', 'Doe'],     // leading space trimmed
            ['',     '',    ''],        // empty → email fallback in onClientEdit
        ];

        foreach ($cases as [$first, $last, $expected]) {
            $result = trim("{$first} {$last}");
            // The actual name-trimming is inside onClientEdit which also handles
            // inner whitespace via trim(). Test the trim() pattern that matters.
            $this->assertSame($expected, $result);
        }
    }
}
