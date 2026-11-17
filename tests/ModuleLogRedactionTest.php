<?php

use PHPUnit\Framework\TestCase;

/**
 * LOG-REDACT-01 (v1.9.6, ported from paneldns-whmcs 1.1.4 f526b01): no live credential
 * reaches tblmodulelog.
 *
 * POST /api/v1/sub-clients/{id}/sso-token answers with data.login_url =
 * https://…/sso/consume?token=…, a one-time portal login valid for 60 seconds. Since 1.9.3
 * (LOG-SCRUB-01) this module cut the token parameter out of it and redacted secret keys at
 * any depth; 1.9.6 aligns with paneldns-whmcs: the whole login URL is redacted, a token
 * cut from any string is also passed to logModuleCall() as a value to mask, and values
 * shorter than 8 characters are not used as replace vars.
 *
 * The bootstrap's logModuleCall() stub records every call in $GLOBALS['__module_log'].
 *
 * @covers \PanelDnsResellerApi
 */
final class ModuleLogRedactionTest extends TestCase
{
    private const KEY   = 'test-reseller-key-0123456789abcdef';
    private const TOKEN = 'Zx81QmT0kLr7aVb3nC5pW2sYd9fGh4jE';

    protected function setUp(): void
    {
        PanelDnsResellerApi::resetRateLimitState();
        $GLOBALS['__module_log'] = [];
    }

    /** @return array{0:string,1:string,2:mixed,3:mixed,4:mixed,5:mixed} the last logModuleCall() */
    private static function lastLog(): array
    {
        $log = $GLOBALS['__module_log'] ?? [];
        self::assertNotEmpty($log, 'the request was logged');
        return array_pad(end($log), 6, null);
    }

    /** Everything the entry would show: action, request, response, processed data. */
    private static function shown(array $entry): string
    {
        return (string) json_encode([$entry[1], $entry[2], $entry[3], $entry[4]], JSON_UNESCAPED_SLASHES);
    }

    private static function api(string $path, array $response, string $method = 'GET'): FakeApi
    {
        return (new FakeApi(self::KEY))->on($method, $path, $response);
    }

    private static function ssoBody(): array
    {
        return ['ok' => true, 'data' => [
            'login_url'  => 'https://paneldns.test/portal/sso/consume?token=' . self::TOKEN,
            'expires_in' => 60,
        ]];
    }

    public function test_the_sso_login_url_is_never_written_to_the_module_log(): void
    {
        $resp = self::api('/api/v1/sub-clients/5/sso-token', FakeApi::json(200, self::ssoBody()), 'POST')->mintSubClientSsoToken(5);

        $entry = self::lastLog();
        $this->assertStringNotContainsString(self::TOKEN, self::shown($entry));
        $this->assertStringNotContainsString('/sso/consume', self::shown($entry));
        $this->assertStringContainsString('[REDACTED]', $entry[3]['response']);

        // The caller still gets the real link.
        $this->assertSame('https://paneldns.test/portal/sso/consume?token=' . self::TOKEN, $resp['data']['login_url']);
    }

    public function test_the_rest_of_the_response_stays_readable(): void
    {
        self::api('/api/v1/sub-clients/5/sso-token', FakeApi::json(200, self::ssoBody()), 'POST')->mintSubClientSsoToken(5);

        $logged = json_decode(self::lastLog()[3]['response'], true);
        $this->assertTrue($logged['ok']);
        $this->assertSame(60, $logged['data']['expires_in']);
        $this->assertSame('[REDACTED]', $logged['data']['login_url']);
        $this->assertSame(200, self::lastLog()[3]['status']);
    }

    public function test_replace_vars_are_secret_values_not_key_names(): void
    {
        self::api('/api/v1/sub-clients/5/sso-token', FakeApi::json(200, self::ssoBody()), 'POST')->mintSubClientSsoToken(5);

        $replace = self::lastLog()[5];
        $this->assertIsArray($replace);
        $this->assertContains(self::KEY, $replace, 'the API key');
        $this->assertContains(self::TOKEN, $replace, 'the minted token');
        foreach (['api_key', 'authorization', 'password', 'secret', 'token'] as $name) {
            $this->assertNotContains($name, $replace, "'{$name}' is a key name, not a value to mask");
        }
    }

    public function test_nested_api_tokens_are_redacted_at_any_depth(): void
    {
        self::api('/api/v1/tokens', FakeApi::json(200, ['ok' => true, 'data' => [
            'tokens' => [['id' => 1, 'plain_text_token' => '7|' . self::TOKEN]],
            'Token'  => self::TOKEN . 'b',
        ]]))->get('/api/v1/tokens');

        $entry = self::lastLog();
        $this->assertStringNotContainsString(self::TOKEN, self::shown($entry));
        $this->assertContains('7|' . self::TOKEN, $entry[5]);
    }

    public function test_a_login_link_in_a_non_json_body_is_cut_and_masked(): void
    {
        self::api('/api/ping', ['status' => 502, 'body' => '<p>Bad gateway for /sso/consume?token=' . self::TOKEN . '&next=/zones</p>',
            'headers' => [], 'curl_error' => '', 'curl_errno' => 0, 'primary_ip' => '203.0.113.10'])->ping();

        $entry = self::lastLog();
        $this->assertStringNotContainsString(self::TOKEN, self::shown($entry));
        $this->assertStringContainsString('token=[REDACTED]&next=/zones', $entry[3]['response']);
        $this->assertContains(self::TOKEN, $entry[5], 'a token cut from a string is masked too');
    }

    public function test_a_sub_client_password_in_a_create_request_is_redacted_and_masked(): void
    {
        $password = 'f3a9c1d27be04e6a8c5d0b1e2f3a4b5c';
        self::api('/api/v1/sub-clients', FakeApi::json(201, ['ok' => true, 'data' => ['id' => 9]]), 'POST')
            ->createSubClient(['name' => 'Jane', 'email' => 'jane@acme.test', 'password' => $password]);

        $entry = self::lastLog();
        $this->assertSame('[REDACTED]', $entry[2]['password']);
        $this->assertStringNotContainsString($password, self::shown($entry));
        $this->assertContains($password, $entry[5]);
    }

    public function test_short_values_are_not_used_as_replace_vars(): void
    {
        // Masking every "abc" in an entry would mangle it; short values are already
        // replaced by key.
        self::api('/x', FakeApi::json(200, ['ok' => true, 'data' => ['token' => 'abc']]))->get('/x');

        $entry = self::lastLog();
        $this->assertSame('[REDACTED]', json_decode($entry[3]['response'], true)['data']['token']);
        $this->assertNotContains('abc', $entry[5]);
    }

    /** @dataProvider unloggable */
    public function test_redact_response_never_returns_the_secret(string $raw): void
    {
        $secrets = [];
        $out = PanelDnsResellerApi::redactResponse($raw, $secrets);
        $this->assertStringNotContainsString(self::TOKEN, (string) $out);
    }

    public static function unloggable(): array
    {
        return [
            'escaped slashes' => ['{"ok":true,"data":{"login_url":"https:\/\/h\/sso\/consume?token=' . self::TOKEN . '"}}'],
            'other key'       => ['{"ok":false,"error":"see https://h/sso/consume?token=' . self::TOKEN . '"}'],
            'upper-case key'  => ['{"ok":true,"data":{"LOGIN_URL":"x?y=' . self::TOKEN . '"}}'],
            'portal sso url'  => ['{"ok":true,"data":{"portal_sso_url":"x?y=' . self::TOKEN . '"}}'],
            'plain text'      => ['token=' . self::TOKEN],
        ];
    }

    public function test_a_word_that_ends_in_key_is_not_treated_as_a_secret(): void
    {
        $this->assertSame('monkey=banana', PanelDnsResellerApi::redactResponse('monkey=banana'));
    }
}
