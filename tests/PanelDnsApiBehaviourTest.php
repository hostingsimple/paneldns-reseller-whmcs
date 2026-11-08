<?php

use PHPUnit\Framework\TestCase;

/**
 * Response handling of PanelDnsResellerApi against canned PanelDNS responses: legal
 * version, error bodies, 429 / Retry-After, transport errors, paging.
 *
 * @covers \PanelDnsResellerApi
 */
final class PanelDnsApiBehaviourTest extends TestCase
{
    protected function setUp(): void
    {
        PanelDnsResellerApi::resetRateLimitState();
        $GLOBALS['__module_log'] = [];
    }

    // ── getLegalVersion (finding 2) ───────────────────────────────────────────

    public function test_legal_version_reads_the_top_level_version_of_the_reseller_endpoint(): void
    {
        // GET /api/v1/legal-version answers with `version` at the top level.
        $api = (new FakeApi())->on('GET', '/api/v1/legal-version', FakeApi::json(200, ['ok' => true, 'version' => '2026-04-01']));

        $this->assertSame('2026-04-01', $api->getLegalVersion());
        $this->assertCount(1, $api->callsTo('GET', '/api/v1/legal-version'));
    }

    public function test_legal_version_also_reads_a_data_wrapped_version(): void
    {
        $api = (new FakeApi())->on('GET', '/api/v1/legal-version', FakeApi::json(200, ['ok' => true, 'data' => ['version' => '2026-06-07']]));
        $this->assertSame('2026-06-07', $api->getLegalVersion());
    }

    /** @dataProvider legalVersionFailureProvider */
    public function test_legal_version_soft_fails_to_empty(array $response): void
    {
        $api = (new FakeApi())->on('GET', '/api/v1/legal-version', $response);
        $this->assertSame('', $api->getLegalVersion());
    }

    public static function legalVersionFailureProvider(): array
    {
        return [
            'not configured'   => [FakeApi::json(200, ['ok' => true, 'version' => ''])],
            'server error'     => [FakeApi::json(500, ['message' => 'Server Error'])],
            'scope refused'    => [FakeApi::json(403, ['ok' => false, 'error' => "Scope 'x' required.", 'code' => 403])],
            'not json'         => [FakeApi::json(200, '<html>oops</html>')],
            'non-string'       => [FakeApi::json(200, ['ok' => true, 'version' => ['x']])],
            'odd characters'   => [FakeApi::json(200, ['ok' => true, 'version' => "2026\n<script>"])],
        ];
    }

    // ── Error bodies (finding 10) ──────────────────────────────────────────────

    public function test_validation_errors_are_listed_per_field(): void
    {
        $api = (new FakeApi())->on('POST', '/api/v1/sub-clients', FakeApi::json(422, [
            'message' => 'The email has already been taken. (and 1 more error)',
            'errors'  => [
                'email'    => ['The email has already been taken.'],
                'password' => ['The password field must be at least 12 characters.'],
            ],
        ]));

        $r = $api->createSubClient(['email' => 'a@b.c']);
        $this->assertFalse($r['ok']);
        $this->assertSame(422, $r['status']);
        $this->assertSame('The email has already been taken. The password field must be at least 12 characters.', $r['error']);
        $this->assertSame('The email has already been taken.', $r['errors']['email']);
    }

    public function test_envelope_error_is_used(): void
    {
        $api = (new FakeApi())->on('POST', '/api/v1/zones', FakeApi::json(409, ['ok' => false, 'error' => 'Zone already exists.', 'code' => 409]));
        $r = $api->createZone('example.com');
        $this->assertSame('Zone already exists.', $r['error']);
        $this->assertSame([], $r['errors']);
    }

    public function test_bare_message_bodies_are_used(): void
    {
        // The "REST API switched off" 403 and the IP-allowlist 403 both use `message`.
        $api = (new FakeApi())
            ->on('GET', '/api/v1/summary', FakeApi::json(403, ['message' => 'REST API tokens is turned off on this platform.']))
            ->on('GET', '/api/v1/org/nameservers', FakeApi::json(403, ['ok' => false, 'message' => 'This token is restricted to specific IP addresses.', 'code' => 403]));

        $this->assertSame('REST API tokens is turned off on this platform.', $api->summary()['error']);
        $this->assertSame('This token is restricted to specific IP addresses.', $api->nameservers()['error']);
    }

    public function test_unparseable_error_falls_back_to_status(): void
    {
        $api = (new FakeApi())->on('GET', '/api/v1/summary', FakeApi::json(502, '<html>Bad gateway</html>'));
        $this->assertSame('PanelDNS returned HTTP 502.', $api->summary()['error']);
    }

    // ── 429 / Retry-After ──────────────────────────────────────────────────────

    public function test_429_reports_retry_after_and_stops_calling_until_it_passes(): void
    {
        $api = (new FakeApi())->on('GET', '/api/v1/summary', FakeApi::json(429, ['message' => 'Too Many Attempts.'], ['Retry-After' => '42']));

        $first = $api->summary();
        $this->assertSame(429, $first['status']);
        $this->assertSame(42, $first['retry_after']);
        $this->assertSame('PanelDNS rate limit reached (retry in 42 seconds).', $first['error']);

        // The next calls within the window are answered locally — no request is sent.
        $second = $api->getSubClient(5);
        $this->assertSame(429, $second['status']);
        $this->assertGreaterThan(0, $second['retry_after']);
        $this->assertCount(1, $api->calls);
    }

    public function test_failed_auth_429_keeps_its_message(): void
    {
        $api = (new FakeApi())->on('GET', '/api/v1/summary', FakeApi::json(
            429,
            ['ok' => false, 'error' => 'Too many failed authentication attempts. Try again shortly.', 'code' => 429],
            ['Retry-After' => '30']
        ));
        $this->assertSame(
            'Too many failed authentication attempts. Try again shortly. (HTTP 429, retry in 30 seconds)',
            $api->summary()['error']
        );
    }

    /** @dataProvider retryAfterProvider */
    public function test_retry_after_parsing(?string $header, int $expected): void
    {
        $this->assertSame($expected, PanelDnsResellerApi::retryAfterSeconds($header));
    }

    public static function retryAfterProvider(): array
    {
        return [
            'seconds'   => ['17', 17],
            'missing'   => [null, 60],
            'garbage'   => ['soon', 60],
            'zero'      => ['0', 1],
            'too large' => ['86400', PanelDnsResellerApi::MAX_RETRY_AFTER],
        ];
    }

    // ── Transport errors are not echoed (SEC-M14) ──────────────────────────────

    public function test_curl_error_text_is_not_returned_to_callers(): void
    {
        $api = (new FakeApi())->on('GET', '/api/v1/summary', [
            'status' => 0, 'body' => null, 'headers' => [], 'primary_ip' => '',
            'curl_error' => 'Failed to connect to internal-db.corp.local port 443: Connection refused (10.1.2.3)',
            'curl_errno' => 7,
        ]);

        $r = $api->summary();
        $this->assertFalse($r['ok']);
        $this->assertSame(0, $r['status']);
        $this->assertStringNotContainsString('corp.local', $r['error']);
        $this->assertStringNotContainsString('10.1.2.3', $r['error']);
        $this->assertStringContainsString('network error 7', $r['error']);
    }

    public function test_zone_file_content_is_not_written_to_the_module_log(): void
    {
        $api = (new FakeApi())->on('POST', '/api/v1/zones/3/import', FakeApi::json(200, ['ok' => true, 'data' => null]));
        $api->post('/api/v1/zones/3/import', ['zone_file' => 'secret-record IN TXT "s3cr3t"']);

        $logged = json_encode($GLOBALS['__module_log']);
        $this->assertStringNotContainsString('s3cr3t', $logged);
        $this->assertStringContainsString('bytes]', $logged);
    }

    // ── Secrets never reach the module log (LOG-SCRUB-01) ──────────────────────

    public function test_nested_secrets_are_redacted_and_their_values_masked(): void
    {
        $api = (new FakeApi('tok-ABCDEFGH-secret'))->on('POST', '/api/v1/sub-clients', FakeApi::json(201, ['ok' => true, 'data' => ['id' => 1]]));
        $api->post('/api/v1/sub-clients', [
            'email'    => 'a@b.c',
            'password' => 'TopLevelPass!123',
            'owner'    => ['password' => 'NestedPass!456', 'name' => 'Owner'],
            'meta'     => [['api_key' => 'deep-key-789']],
        ]);

        [$module, $action, $request, $response, $processed, $replace] = $GLOBALS['__module_log'][0];
        $flat = json_encode([$request, $response]);
        foreach (['TopLevelPass!123', 'NestedPass!456', 'deep-key-789'] as $secret) {
            $this->assertStringNotContainsString($secret, $flat);
            $this->assertContains($secret, $replace, 'secret values go to logModuleCall replace-vars');
        }
        $this->assertSame('[REDACTED]', $request['owner']['password']);
        $this->assertSame('Owner', $request['owner']['name']);
        $this->assertContains('tok-ABCDEFGH-secret', $replace, 'the API key itself is masked anywhere it appears');
        $this->assertNotContains('password', $replace, 'replace-vars are values, not key names');
    }

    public function test_sso_token_in_a_response_is_redacted(): void
    {
        $api = (new FakeApi())->on('POST', '/api/v1/sub-clients/5/sso-token', FakeApi::json(200, ['ok' => true, 'data' => [
            'login_url' => 'https://paneldns.test/portal/sso/consume?token=LIVE-ONE-TIME-TOKEN', 'expires_in' => 60,
        ]]));
        $r = $api->mintSubClientSsoToken(5);
        $this->assertStringContainsString('LIVE-ONE-TIME-TOKEN', $r['data']['login_url'], 'callers still get the real URL');

        $logged = json_encode($GLOBALS['__module_log']);
        $this->assertStringNotContainsString('LIVE-ONE-TIME-TOKEN', $logged);
        $this->assertStringContainsString('token=[REDACTED]', $logged);
    }

    // ── Paging (finding 7) ─────────────────────────────────────────────────────

    /** A list endpoint that pages at a fixed 50 and ignores per_page, like PanelDNS. */
    private function pagedSubClients(int $total, ?int $failOnPage = null): FakeApi
    {
        return (new FakeApi())->on('GET', '/api/v1/sub-clients', function (array $q) use ($total, $failOnPage) {
            $page = max(1, (int) ($q['page'] ?? 1));
            if ($page === $failOnPage) {
                return FakeApi::json(500, ['message' => 'Server Error']);
            }
            $rows = [];
            for ($i = ($page - 1) * 50 + 1; $i <= min($total, $page * 50); $i++) {
                $rows[] = ['id' => $i, 'org_id' => 1, 'email' => "c{$i}@example.com", 'name' => "C{$i}", 'status' => 'active', 'zone_limit' => 5];
            }
            return FakeApi::json(200, ['ok' => true, 'data' => $rows, 'meta' => ['page' => $page, 'per_page' => 50, 'total' => $total]]);
        });
    }

    public function test_get_all_pages_follows_meta_past_the_fixed_page_size(): void
    {
        $api = $this->pagedSubClients(120);
        $r = $api->getAllPages('/api/v1/sub-clients', ['per_page' => 100]);

        $this->assertTrue($r['ok']);
        $this->assertTrue($r['complete']);
        $this->assertCount(120, $r['data']);
        $this->assertSame(120, $r['total']);
        $this->assertCount(3, $api->calls);
        $this->assertSame(['1', '2', '3'], array_map(fn ($c) => $c['query']['page'], $api->calls));
    }

    public function test_get_all_pages_stops_after_an_exact_multiple(): void
    {
        $api = $this->pagedSubClients(100);
        $r = $api->getAllPages('/api/v1/sub-clients');
        $this->assertCount(100, $r['data']);
        $this->assertCount(2, $api->calls, 'no third request when page*per_page reaches total');
    }

    public function test_get_all_pages_reports_truncation_at_the_page_cap(): void
    {
        $api = $this->pagedSubClients(500);
        $r = $api->getAllPages('/api/v1/sub-clients', [], 3);
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['truncated']);
        $this->assertFalse($r['complete']);
        $this->assertCount(150, $r['data']);
    }

    public function test_get_all_pages_reports_a_failed_page(): void
    {
        $api = $this->pagedSubClients(120, 2);
        $r = $api->getAllPages('/api/v1/sub-clients');
        $this->assertFalse($r['ok']);
        $this->assertFalse($r['complete']);
        $this->assertCount(50, $r['data'], 'pages before the failure are kept');
    }

    public function test_get_all_pages_handles_an_empty_list(): void
    {
        $api = $this->pagedSubClients(0);
        $r = $api->getAllPages('/api/v1/sub-clients');
        $this->assertTrue($r['ok']);
        $this->assertSame([], $r['data']);
        $this->assertCount(1, $api->calls);
    }

    public function test_find_sub_client_by_email_is_exact_and_case_insensitive_across_pages(): void
    {
        $api = $this->pagedSubClients(80);
        $hit = $api->findSubClientByEmail('C77@Example.com');
        $this->assertTrue($hit['ok']);
        $this->assertSame(77, $hit['sub_client']['id']);

        // A LIKE match that is not an exact match is not a hit.
        $miss = $this->pagedSubClients(80)->findSubClientByEmail('c7@example.co');
        $this->assertTrue($miss['ok']);
        $this->assertNull($miss['sub_client']);
    }
}
