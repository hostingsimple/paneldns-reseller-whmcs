<?php

use PHPUnit\Framework\TestCase;

/**
 * @covers \PanelDnsResellerWelcomeMail
 */
final class WelcomeMailTest extends TestCase
{
    /**
     * @dataProvider humanExpiryProvider
     */
    public function test_human_expiry_formats(int $seconds, string $expected): void
    {
        $this->assertSame($expected, PanelDnsResellerWelcomeMail::humanExpiry($seconds));
    }

    public static function humanExpiryProvider(): array
    {
        return [
            'sub-minute'   => [30,    '30 seconds'],
            'one-minute'   => [60,    '60 seconds'],
            'two-minutes'  => [120,   '2 minutes'],
            'fifty-mins'   => [3000,  '50 minutes'],
            'one-hour'     => [3600,  '1 hours'],
            'six-hours'    => [21600, '6 hours'],
        ];
    }

    public function test_template_constants_are_distinct(): void
    {
        $this->assertNotSame(
            PanelDnsResellerWelcomeMail::TEMPLATE_PLATFORM,
            PanelDnsResellerWelcomeMail::TEMPLATE_RESELLER
        );
        $this->assertStringContainsString('Platform', PanelDnsResellerWelcomeMail::TEMPLATE_PLATFORM);
        $this->assertStringContainsString('Reseller', PanelDnsResellerWelcomeMail::TEMPLATE_RESELLER);
    }
}
