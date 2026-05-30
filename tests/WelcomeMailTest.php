<?php

use PHPUnit\Framework\TestCase;

/**
 * @covers \PanelDnsWelcomeMail
 */
final class WelcomeMailTest extends TestCase
{
    /**
     * @dataProvider humanExpiryProvider
     */
    public function test_human_expiry_formats(int $seconds, string $expected): void
    {
        $this->assertSame($expected, PanelDnsWelcomeMail::humanExpiry($seconds));
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
            PanelDnsWelcomeMail::TEMPLATE_PLATFORM,
            PanelDnsWelcomeMail::TEMPLATE_RESELLER
        );
        $this->assertStringContainsString('Platform', PanelDnsWelcomeMail::TEMPLATE_PLATFORM);
        $this->assertStringContainsString('Reseller', PanelDnsWelcomeMail::TEMPLATE_RESELLER);
    }
}
