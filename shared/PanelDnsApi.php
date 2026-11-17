<?php

// M-19: file guard — prevent direct web access outside WHMCS context.
if (!defined('WHMCS')) { die('Access denied.'); }

/**
 * PanelDnsResellerApi — HTTP client wrapping the PanelDNS Platform + Public APIs.
 *
 * Used by both paneldnsplatform (operator tier, /platform/v1) and
 * paneldnsreseller (reseller tier, /api/v1) WHMCS server modules. The
 * tier is determined by the api_mode passed in the constructor and the
 * key supplied (PLATFORM_API_KEY-shaped vs Sanctum Bearer).
 *
 * Conventions:
 *   - No Composer, no namespaces (lives in shared/, copied into lib/ by build.php).
 *   - cURL only (no Guzzle).
 *   - IPv4 only (CURLOPT_IPRESOLVE_V4) — SSRF guard, matches dns-whmcs.
 *   - SSRF-PIN-01 (v1.9.6): the host is resolved once per client, a private/reserved
 *     address is refused before anything is sent, and cURL is pinned to the checked
 *     address with CURLOPT_RESOLVE (SNI and certificate checks stay on the hostname).
 *   - TLS verify ON by default; per-server tls_verify flag honoured.
 *   - Returns ['ok' => bool, 'status' => int, 'data' => array|null, 'meta' => array|null,
 *     'error' => string|null, 'retry_after' => int|null, 'raw_body' => string|null].
 *   - Redacts the API key from any logModuleCall payload.
 *
 * Error bodies (API-ERRORS-01). PanelDNS answers errors in three shapes, all JSON:
 *   - the envelope            {"ok": false, "error": "Zone already exists.", "code": 409}
 *   - Laravel validation      {"message": "…", "errors": {"email": ["…"]}}         (422)
 *   - a bare message          {"message": "REST API tokens is turned off …"}       (401/403/429)
 * parseError() turns any of them into one readable line; the field errors are also
 * returned under 'errors' so callers can react to a specific field (duplicate email).
 */

if (!defined('WHMCS_PANELDNS_API_VERSION')) {
    define('WHMCS_PANELDNS_API_VERSION', '0.1.0');
}

if (!class_exists('PanelDnsResellerApi', false)) {
class PanelDnsResellerApi
{
    const MODE_PLATFORM = 'platform';
    const MODE_RESELLER = 'reseller';

    /** RATE-429-01: never wait longer than this before trying again in the same request. */
    const MAX_RETRY_AFTER = 300;

    /** @var string */ private $baseUrl;
    /** @var string */ private $apiKey;
    /** @var string */ private $mode;
    /** @var bool */   private $tlsVerify;
    /** @var int */    private $timeout = 15;

    /**
     * RATE-429-01: per server+key, the unix time before which we do not call again.
     * Set from a 429's Retry-After so a loop (drift sync, bulk sync, paging) stops
     * hammering a server that has already said "slow down".
     *
     * @var array<string,int>
     */
    private static $blockedUntil = [];

    /**
     * SSRF-PIN-01: host => the IPv4 address every request of this client connects to,
     * resolved and checked once.
     *
     * @var array<string,string>
     */
    private $pinned = [];

    /**
     * SSRF-PIN-01: the checked target of the request in flight (host, port, ip), which
     * transport() hands to cURL. Protected so the test double can record it.
     *
     * @var null|array{host:string, port:int, ip:string}
     */
    protected $pin = null;

    public function __construct(string $baseUrl, string $apiKey, string $mode, bool $tlsVerify = true)
    {
        $this->baseUrl   = rtrim($baseUrl, '/');
        $this->apiKey    = $apiKey;
        $this->mode      = $mode === self::MODE_PLATFORM ? self::MODE_PLATFORM : self::MODE_RESELLER;
        $this->tlsVerify = $tlsVerify;

        // M-12: warn when using plaintext HTTP — Bearer token will be transmitted unencrypted.
        if (str_starts_with($this->baseUrl, 'http://')) {
            $host = parse_url($this->baseUrl, PHP_URL_HOST) ?? '(unknown)';
            if (function_exists('logModuleCall')) {
                logModuleCall(
                    'paneldns',
                    'PanelDnsResellerApi::WARNING',
                    ['server' => $host],
                    'API calls over plaintext HTTP — Bearer token transmitted unencrypted',
                    ''
                );
            }
        }
    }

    /** Returns the URL prefix used for this mode. */
    public function prefix(): string
    {
        return $this->mode === self::MODE_PLATFORM ? '/platform/v1' : '/api/v1';
    }

    /**
     * Stable opaque identifier for THIS server+key+mode tuple. Used for
     * cache keys (PanelDnsResellerLicenceCheck) so two servers with different keys don't
     * collide and the same server consistently hits the same cache entry
     * across requests. Never includes the raw key.
     */
    public function identityHash(): string
    {
        // SEC-L05: sha256 replaces sha1 (deprecated for crypto; belt-and-braces even for cache keys).
        return substr(hash('sha256', $this->baseUrl . '|' . $this->mode . '|' . $this->apiKey), 0, 16);
    }

    // ── Generic HTTP ──────────────────────────────────────────────────────────

    public function get(string $path, array $query = []): array
    {
        $url = $this->baseUrl . $path . (!empty($query) ? '?' . http_build_query($query) : '');
        return $this->request('GET', $url);
    }

    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $this->baseUrl . $path, $body);
    }

    public function put(string $path, array $body = []): array
    {
        return $this->request('PUT', $this->baseUrl . $path, $body);
    }

    public function patch(string $path, array $body = []): array
    {
        return $this->request('PATCH', $this->baseUrl . $path, $body);
    }

    public function delete(string $path): array
    {
        return $this->request('DELETE', $this->baseUrl . $path);
    }

    /**
     * PAGING-01: fetch every page of a paginated list endpoint by following `meta`.
     *
     * PanelDNS pages /api/v1/sub-clients and /api/v1/zones at a FIXED 50 per page and
     * ignores `per_page` there (only /zones/{id}/records honours it, 1–500). Callers that
     * asked for 100 and stopped on a short page therefore only ever saw the first 50.
     * This follows meta.total / meta.per_page instead of guessing from the page size.
     *
     * @return array{ok:bool, status:int, data:array, error:?string, total:?int,
     *               complete:bool, truncated:bool, retry_after:?int}
     *   complete  — every page was fetched
     *   truncated — stopped at $maxPages with more remaining (data is a prefix)
     *   On a failed page, ok=false and data holds what was fetched before it.
     */
    public function getAllPages(string $path, array $query = [], int $maxPages = 100): array
    {
        $items = [];
        $total = null;
        $page  = 1;
        $maxPages = max(1, $maxPages);

        while (true) {
            $resp = $this->get($path, array_merge($query, ['page' => $page]));
            if (!$resp['ok']) {
                return [
                    'ok' => false, 'status' => (int) $resp['status'], 'data' => $items,
                    'error' => $resp['error'], 'total' => $total, 'complete' => false,
                    'truncated' => false, 'retry_after' => $resp['retry_after'] ?? null,
                ];
            }

            $batch = (is_array($resp['data']) && array_is_list($resp['data'])) ? $resp['data'] : [];
            foreach ($batch as $row) {
                $items[] = $row;
            }

            $meta    = is_array($resp['meta'] ?? null) ? $resp['meta'] : [];
            $perPage = isset($meta['per_page']) ? max(1, (int) $meta['per_page']) : max(1, count($batch));
            $total   = isset($meta['total']) ? (int) $meta['total'] : null;

            $done = $batch === []
                || ($total !== null && ($page * $perPage >= $total || count($items) >= $total))
                || ($total === null && count($batch) < $perPage);

            if ($done) {
                return [
                    'ok' => true, 'status' => (int) $resp['status'], 'data' => $items, 'error' => null,
                    'total' => $total ?? count($items), 'complete' => true, 'truncated' => false,
                    'retry_after' => null,
                ];
            }

            if ($page >= $maxPages) {
                return [
                    'ok' => true, 'status' => (int) $resp['status'], 'data' => $items, 'error' => null,
                    'total' => $total, 'complete' => false, 'truncated' => true, 'retry_after' => null,
                ];
            }
            $page++;
        }
    }

    // ── Health / status ───────────────────────────────────────────────────────

    public function ping(): array
    {
        return $this->mode === self::MODE_PLATFORM
            ? $this->get('/platform/v1/ping')
            : $this->get('/api/ping');
    }

    public function licenceStatus(): array
    {
        // Reseller mode only — platform mode operators don't need a licence check.
        return $this->get('/api/v1/licence-status');
    }

    /**
     * CONSENT-R-01 / GDPR-LEGAL-01: the legal version currently in force, for stamping
     * `terms_version` on a new sub-client. Reseller tier: GET /api/v1/legal-version,
     * which answers {"ok": true, "version": "2026-04-01"} — `version` at the TOP level,
     * not under `data` (the operator tier's /platform/v1/legal-version nests it under
     * `data`; both shapes are read here).
     *
     * Soft-fail: returns '' on any failure, or when the platform has no version set,
     * so consent stamping never blocks provisioning.
     */
    public function getLegalVersion(): string
    {
        $resp = $this->get($this->prefix() . '/legal-version');
        if (!$resp['ok'] || !is_array($resp['data'])) {
            return '';
        }

        $version = $resp['data']['version'] ?? '';
        if (!is_string($version)) {
            return '';
        }
        $version = trim($version);

        // The value is echoed back to PanelDNS as terms_version. Accept only a short,
        // printable identifier so a broken upstream can never inject anything odd.
        return preg_match('/^[A-Za-z0-9._:\-]{1,64}$/', $version) === 1 ? $version : '';
    }

    // ── Platform tier helpers ─────────────────────────────────────────────────

    public function plans(): array { return $this->get('/platform/v1/plans'); }

    public function createOrg(array $data): array { return $this->post('/platform/v1/orgs', $data); }
    public function getOrg(int $id): array        { return $this->get("/platform/v1/orgs/{$id}"); }
    public function patchOrg(int $id, array $d): array { return $this->patch("/platform/v1/orgs/{$id}", $d); }
    public function changePlan(int $id, int $planId): array { return $this->put("/platform/v1/orgs/{$id}/plan", ['plan_id' => $planId]); }
    public function suspendOrg(int $id): array    { return $this->post("/platform/v1/orgs/{$id}/suspend"); }
    public function unsuspendOrg(int $id): array  { return $this->post("/platform/v1/orgs/{$id}/unsuspend"); }
    public function terminateOrg(int $id): array  { return $this->delete("/platform/v1/orgs/{$id}"); }
    public function orgSummary(int $id): array    { return $this->get("/platform/v1/orgs/{$id}/summary"); }
    public function mintOrgSsoToken(int $id, ?string $email = null): array
    {
        return $this->post("/platform/v1/orgs/{$id}/sso-token", $email ? ['user_email' => $email] : []);
    }

    // ── Reseller tier helpers ─────────────────────────────────────────────────

    public function summary(): array { return $this->get('/api/v1/summary'); }
    public function nameservers(): array { return $this->get('/api/v1/org/nameservers'); }

    public function createSubClient(array $data): array { return $this->post('/api/v1/sub-clients', $data); }
    public function getSubClient(int $id): array        { return $this->get("/api/v1/sub-clients/{$id}"); }
    public function patchSubClient(int $id, array $d): array { return $this->patch("/api/v1/sub-clients/{$id}", $d); }
    public function deleteSubClient(int $id): array     { return $this->delete("/api/v1/sub-clients/{$id}"); }
    public function subClientSummary(int $id): array    { return $this->get("/api/v1/sub-clients/{$id}/summary"); }
    public function mintSubClientSsoToken(int $id): array
    {
        return $this->post("/api/v1/sub-clients/{$id}/sso-token");
    }

    /**
     * Search sub-clients by email (LIKE match on name+email server-side).
     * Returns one page (PanelDNS pages at a fixed 50; per_page is ignored). Callers must
     * filter results client-side for an exact email match — the server uses a LIKE query
     * that may surface partial matches. Prefer findSubClientByEmail().
     */
    public function searchSubClients(string $email, int $page = 1): array
    {
        return $this->get('/api/v1/sub-clients', [
            'search' => $email,
            'page'   => $page,
        ]);
    }

    /**
     * DUP-EMAIL-01: exact, case-insensitive email lookup across every page of the
     * search results. The list is scoped server-side to the token's org, so a match
     * can only ever be a sub-client of the org this token acts on.
     *
     * @return array{ok:bool, sub_client:?array, error:?string, status:int}
     */
    public function findSubClientByEmail(string $email, int $maxPages = 5): array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return ['ok' => true, 'sub_client' => null, 'error' => null, 'status' => 0];
        }

        $resp = $this->getAllPages('/api/v1/sub-clients', ['search' => $email], $maxPages);
        foreach ($resp['data'] as $sc) {
            if (is_array($sc) && strtolower((string) ($sc['email'] ?? '')) === $email) {
                return ['ok' => true, 'sub_client' => $sc, 'error' => null, 'status' => (int) $resp['status']];
            }
        }

        if (!$resp['ok']) {
            return ['ok' => false, 'sub_client' => null, 'error' => $resp['error'], 'status' => (int) $resp['status']];
        }
        return ['ok' => true, 'sub_client' => null, 'error' => null, 'status' => (int) $resp['status']];
    }

    // ── Zones (reseller tier) ─────────────────────────────────────────────────

    /**
     * Create a zone via /api/v1/zones. Requires zones:write scope on the
     * Sanctum token in reseller mode.
     *
     * @param string   $name       FQDN to create (lowercased server-side)
     * @param int|null $subClientId Optional sub-client to attach the zone to
     * @param array    $extra       Optional: provider_id, notes
     */
    public function createZone(string $name, ?int $subClientId = null, array $extra = []): array
    {
        $payload = array_filter([
            'name'          => $name,
            'sub_client_id' => $subClientId,
            'provider_id'   => $extra['provider_id'] ?? null,
            'notes'         => $extra['notes']       ?? null,
        ], fn ($v) => $v !== null);
        return $this->post('/api/v1/zones', $payload);
    }

    // ── Error parsing ─────────────────────────────────────────────────────────

    /**
     * API-ERRORS-01: one readable line from any PanelDNS error body.
     *
     * @internal public for unit tests.
     */
    public static function parseError($decoded, int $status, ?int $retryAfter = null): string
    {
        $message = '';
        if (is_array($decoded)) {
            if (isset($decoded['error']) && is_string($decoded['error']) && trim($decoded['error']) !== '') {
                $message = trim($decoded['error']);
            } elseif (isset($decoded['message']) && is_string($decoded['message']) && trim($decoded['message']) !== '') {
                $message = trim($decoded['message']);
            }

            // Laravel validation: {"message": "...", "errors": {"field": ["..."]}}. The
            // top-level message only repeats the first error ("… (and 1 more error)"),
            // so list each field's first message instead.
            $fieldErrors = self::fieldErrors($decoded);
            if ($fieldErrors !== []) {
                $parts = [];
                foreach ($fieldErrors as $field => $msg) {
                    $parts[] = $msg;
                }
                $message = implode(' ', $parts);
            }
        }

        if ($status === 429) {
            $wait = $retryAfter !== null
                ? "retry in {$retryAfter} second" . ($retryAfter === 1 ? '' : 's')
                : 'retry shortly';
            // Laravel's plan limiter says only "Too Many Attempts."; the failed-auth
            // limiter says something specific worth keeping.
            if ($message === '' || strcasecmp(rtrim($message, '.'), 'Too Many Attempts') === 0) {
                return "PanelDNS rate limit reached ({$wait}).";
            }
            return self::cap("{$message} (HTTP 429, {$wait})");
        }

        if ($message === '') {
            return "PanelDNS returned HTTP {$status}.";
        }
        return self::cap($message);
    }

    /**
     * The first message for each field of a Laravel validation body.
     *
     * @return array<string,string>
     * @internal public for unit tests.
     */
    public static function fieldErrors($decoded): array
    {
        if (!is_array($decoded) || !isset($decoded['errors']) || !is_array($decoded['errors'])) {
            return [];
        }
        $out = [];
        foreach ($decoded['errors'] as $field => $messages) {
            $first = is_array($messages) ? reset($messages) : $messages;
            if (is_string($first) && trim($first) !== '') {
                $out[(string) $field] = trim($first);
            }
        }
        return $out;
    }

    private static function cap(string $s): string
    {
        // Strip control characters and cap — the line ends up in WHMCS admin and
        // client-area messages.
        $s = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s) ?? '';
        return mb_strlen($s) > 500 ? mb_substr($s, 0, 500) . '…' : $s;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function request(string $method, string $url, ?array $body = null): array
    {
        $identity = $this->identityHash();

        // RATE-429-01: honour an earlier Retry-After without touching the network.
        $until = self::$blockedUntil[$identity] ?? 0;
        if ($until > time()) {
            $wait = $until - time();
            return [
                'ok' => false, 'status' => 429, 'data' => null, 'meta' => null, 'errors' => [],
                'error' => self::parseError(null, 429, $wait), 'retry_after' => $wait, 'raw_body' => null,
            ];
        }

        // SSRF-PIN-01 (v1.9.6): resolve the host once, refuse a private or reserved address,
        // and pin that address for cURL — all before anything is sent. Up to 1.9.5 the
        // callers checked gethostbyname() and cURL then resolved the name again, so a
        // short-TTL record could pass the check and point the request (and its Bearer
        // token) at an internal address; the private-IP check here ran only after cURL had
        // sent the request.
        $target = $this->pinTarget($url);
        if (isset($target['error'])) {
            $this->logCall($method, $url, $body, 0, null, $target['error']);
            return [
                'ok' => false, 'status' => 0, 'data' => null, 'meta' => null, 'errors' => [],
                'error' => $target['error'], 'retry_after' => null, 'raw_body' => null,
            ];
        }

        $this->pin = $target;
        try {
            $res = $this->transport($method, $url, $body);
        } finally {
            $this->pin = null;
        }
        $status = (int) ($res['status'] ?? 0);
        $raw    = $res['body'] ?? null;
        $err    = (string) ($res['curl_error'] ?? '');

        $this->logCall($method, $url, $body, $status, is_string($raw) ? $raw : null, $err);

        if ($err !== '') {
            // SEC-M14: the raw cURL message names hosts, IPs and TLS detail. It is in the
            // module log (above); callers — which may show 'error' to admins or clients —
            // get a generic line.
            $errno = (int) ($res['curl_errno'] ?? 0);
            return [
                'ok' => false, 'status' => 0, 'data' => null, 'meta' => null, 'errors' => [],
                'error' => 'Could not connect to PanelDNS' . ($errno > 0 ? " (network error {$errno})" : '') . '. See the module log for details.',
                'retry_after' => null, 'raw_body' => null,
            ];
        }

        // SEC-L02: belt-and-braces — discard a response that still came from a private
        // address (e.g. through a proxy). This runs after the request was sent, so it is
        // not the guard: the pinned pre-flight above is (SSRF-PIN-01).
        $primaryIp = (string) ($res['primary_ip'] ?? '');
        if ($primaryIp !== '' && self::isPrivateIp($primaryIp)) {
            return [
                'ok' => false, 'status' => 0, 'data' => null, 'meta' => null, 'errors' => [],
                'error' => 'SSRF guard: target resolved to a private IP address', 'retry_after' => null, 'raw_body' => null,
            ];
        }

        $retryAfter = null;
        if ($status === 429) {
            $retryAfter = self::retryAfterSeconds($res['headers']['retry-after'] ?? null);
            self::$blockedUntil[$identity] = time() + $retryAfter;
        }

        $decoded = json_decode(is_string($raw) && $raw !== '' ? $raw : 'null', true);
        $ok = $status >= 200 && $status < 300 && is_array($decoded) && !empty($decoded['ok']);

        return [
            'ok'          => $ok,
            'status'      => $status,
            'data'        => is_array($decoded) ? ($decoded['data'] ?? $decoded) : null,
            'meta'        => is_array($decoded) && is_array($decoded['meta'] ?? null) ? $decoded['meta'] : null,
            'errors'      => $ok ? [] : self::fieldErrors($decoded),
            'error'       => $ok ? null : self::parseError($decoded, $status, $retryAfter),
            'retry_after' => $retryAfter,
            // EXPORT-01: raw body for non-JSON endpoints (e.g. zone BIND export returns
            // text/plain). Callers check 'status' directly and read 'raw_body'.
            'raw_body'    => $raw,
        ];
    }

    /**
     * The network call, isolated so tests can substitute canned responses.
     *
     * @return array{status:int, body:?string, headers:array<string,string>, curl_error:string, curl_errno:int, primary_ip:string}
     */
    protected function transport(string $method, string $url, ?array $body): array
    {
        $ch = curl_init();
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'User-Agent: paneldns-whmcs/' . WHMCS_PANELDNS_API_VERSION,
        ];
        $responseHeaders = [];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => $this->tlsVerify ? 1 : 0,
            CURLOPT_SSL_VERIFYHOST => $this->tlsVerify ? 2 : 0,
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            // RATE-429-01: capture Retry-After (and nothing else) from the response headers.
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $name = strtolower(trim(substr($line, 0, $pos)));
                    if ($name === 'retry-after') {
                        $responseHeaders[$name] = trim(substr($line, $pos + 1));
                    }
                }
                return strlen($line);
            },
        ];

        // SSRF-PIN-01: connect to the address request() checked. The URL keeps the
        // hostname, so SNI and certificate verification still use the hostname.
        $resolve = $this->pin !== null ? self::curlResolveEntry($this->pin) : null;
        if ($resolve !== null) {
            $opts[CURLOPT_RESOLVE] = [$resolve];
        }

        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;

        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        // PHP8-CURL-01: the handle is intentionally left to be freed by refcount. The
        // explicit close has had no effect since PHP 8.0 and is deprecated in PHP 8.5,
        // where it emits a deprecation notice on EVERY API call. WHMCS 8.7, HostBill and
        // Blesta 5 all require PHP 8, so no supported platform still needs it.
        return [
            'status'     => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'body'       => is_string($raw) ? $raw : null,
            'headers'    => $responseHeaders,
            'curl_error' => (string) curl_error($ch),
            'curl_errno' => (int) curl_errno($ch),
            'primary_ip' => (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP),
        ];
    }

    /**
     * SSRF-PIN-01 (v1.9.6): the address a request to $url will connect to, checked before
     * anything is sent. The host is resolved once per client (later requests, such as the
     * next page of a listing, reuse the pin, so the name cannot be re-pointed between
     * them). IPv4 only, like CURLOPT_IPRESOLVE_V4.
     *
     * @return array{host:string, port:int, ip:string}|array{error:string}
     */
    private function pinTarget(string $url): array
    {
        $parts  = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || !in_array($scheme, ['http', 'https'], true)) {
            return ['error' => 'SSRF guard: not an http(s) URL with a host'];
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $ip = $host;
        } elseif (str_starts_with($host, '[')) {
            return ['error' => 'SSRF guard: IPv6 addresses are not supported'];
        } else {
            $ip = $this->pinned[$host] ?? null;
            if ($ip === null) {
                foreach ($this->resolveHost($host) as $candidate) {
                    if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                        $ip = $candidate;
                        break;
                    }
                }
            }
            if ($ip === null) {
                return ['error' => 'Could not resolve the PanelDNS server hostname. See the module log for details.'];
            }
        }

        if (self::isPrivateIp($ip)
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['error' => 'SSRF guard: target resolved to a private IP address'];
        }

        $this->pinned[$host] = $ip;
        return ['host' => $host, 'port' => $port, 'ip' => $ip];
    }

    /**
     * SSRF-PIN-01: the host's IPv4 addresses. Isolated so tests can substitute a resolver.
     *
     * @return string[]
     */
    protected function resolveHost(string $host): array
    {
        $ips = gethostbynamel($host);
        return is_array($ips) ? $ips : [];
    }

    /**
     * SSRF-PIN-01: the CURLOPT_RESOLVE entry ("host:port:ip") for a checked target, or
     * null when the URL already names the IP.
     *
     * @param array{host:string, port:int, ip:string} $target
     * @internal public for unit tests.
     */
    public static function curlResolveEntry(array $target): ?string
    {
        if (filter_var($target['host'], FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        return $target['host'] . ':' . (int) $target['port'] . ':' . $target['ip'];
    }

    /**
     * Retry-After is either delta-seconds or an HTTP date. Missing or unparsable → 60s
     * (PanelDNS limits per minute). Clamped to 1..MAX_RETRY_AFTER.
     *
     * @internal public for unit tests.
     */
    public static function retryAfterSeconds(?string $header): int
    {
        $header = trim((string) $header);
        if ($header !== '' && ctype_digit($header)) {
            $secs = (int) $header;
        } elseif ($header !== '' && ($ts = strtotime($header)) !== false) {
            $secs = $ts - time();
        } else {
            $secs = 60;
        }
        return max(1, min(self::MAX_RETRY_AFTER, $secs));
    }

    /** @internal test helper — forget any recorded Retry-After windows. */
    public static function resetRateLimitState(): void
    {
        self::$blockedUntil = [];
    }

    private function logCall(string $method, string $url, ?array $body, int $status, ?string $rawResponse, ?string $curlError): void
    {
        if (!function_exists('logModuleCall')) return;

        // LOG-SCRUB-01 (v1.9.3): redact secrets at ANY depth of the request and the
        // response. Up to 1.9.2 only top-level request keys were redacted, the response
        // was logged raw (an SSO login_url carries a live one-time token), and the
        // replace-vars argument held key NAMES ('password', 'token', …) — WHMCS masks the
        // literal strings it is given, so it masked those words and no secret at all.
        // It now receives the secret VALUES: the API key plus every value found under a
        // secret-looking key.
        // LOG-REDACT-01 (v1.9.6, ported from paneldns-whmcs 1.1.4): login_url / sso_url
        // values are redacted whole (they ARE the login), a token cut from any string is
        // masked too, and short values are not used as replace vars.
        $secrets = [];
        $request  = self::scrub($body ?? [], $secrets);
        $response = self::redactResponse($rawResponse, $secrets);

        logModuleCall(
            'paneldns',
            // SEC-L01: also strip PII query params (search/email) from the logged URL.
            "{$method} " . $this->redactUrl($url),
            $request,
            ['status' => $status, 'response' => $this->truncate($response, 4096), 'curl_error' => $curlError],
            null,
            $this->replaceVars($secrets)
        );
    }

    /**
     * LOG-REDACT-01: shortest secret passed to logModuleCall() as a replace var. Shorter
     * values are already redacted by key; masking every occurrence of a short string
     * would mangle the rest of the log entry.
     */
    const MIN_REPLACE_VAR_LENGTH = 8;

    /**
     * LOG-SCRUB-01 / LOG-REDACT-01: logModuleCall()'s 6th argument is "an array of strings
     * for replacement": the secret VALUES WHMCS masks wherever they appear in the entry
     * (developers.whmcs.com/provisioning-modules/module-logging) — the API key plus every
     * value scrub()/redactResponse() removed. Never key names.
     *
     * @param list<mixed> $secrets
     * @return list<string>
     */
    private function replaceVars(array $secrets): array
    {
        $vars = [];
        foreach (array_merge([$this->apiKey], $secrets) as $v) {
            if (is_string($v) && strlen($v) >= self::MIN_REPLACE_VAR_LENGTH) $vars[$v] = true;
        }
        return array_keys($vars);
    }

    /**
     * Keys whose values are never logged, at any depth. LOG-REDACT-01 (v1.9.6): also
     * login_url / sso_url — POST /sub-clients/{id}/sso-token's `/sso/consume?token=…` is a
     * live portal login for 60 seconds, so the whole URL goes, not only its token.
     */
    const SECRET_KEY_PATTERN = '/pass(?:word|phrase)?|secret|token|api[_-]?key|authori[sz]ation|access[_-]?hash|private[_-]?key|login[_-]?url|sso[_-]?url/i';

    /**
     * LOG-SCRUB-01: a copy of $data with every secret-looking key's value replaced by
     * '[REDACTED]' at any depth, secret query parameters inside strings redacted, and
     * zone files reduced to their size. The secret values found are appended to $found.
     *
     * @internal public for unit tests.
     */
    public static function scrub($data, array &$found = [])
    {
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                if (is_string($k) && preg_match(self::SECRET_KEY_PATTERN, $k)) {
                    // LOG-REDACT-01: every string under the key (a list of tokens too), and a
                    // login URL's token on its own, in case WHMCS logs it elsewhere.
                    $values = is_array($v) ? [] : [$v];
                    if (is_array($v)) {
                        array_walk_recursive($v, function ($leaf) use (&$values) { $values[] = $leaf; });
                    }
                    foreach ($values as $value) {
                        if (!is_string($value) || $value === '') continue;
                        $found[] = $value;
                        parse_str((string) parse_url($value, PHP_URL_QUERY), $q);
                        if (is_string($q['token'] ?? null) && $q['token'] !== '') $found[] = $q['token'];
                    }
                    $out[$k] = '[REDACTED]';
                } elseif ($k === 'zone_file' && is_string($v)) {
                    // IMPORT-01: a zone file can be 50 KB; log its size, not its content.
                    $out[$k] = '[' . strlen($v) . ' bytes]';
                } else {
                    $out[$k] = self::scrub($v, $found);
                }
            }
            return $out;
        }
        return is_string($data) ? self::scrubString($data, $found) : $data;
    }

    /**
     * Secret parameters (token=…, password=…, key=…) anywhere in a string — a query
     * string, a URL under another key, an HTML error page. LOG-REDACT-01: matched at any
     * position not inside a longer word (up to 1.9.5 only after ? or &), and every value
     * cut is added to $found so WHMCS masks it elsewhere too.
     */
    private static function scrubString(string $s, array &$found = []): string
    {
        return (string) preg_replace_callback(
            '/(?<![A-Za-z0-9_])((?:access_token|api_key|apikey|token|key|password|secret)=)([^&"\'\s<>\\\\]+)/i',
            static function (array $m) use (&$found): string {
                if ($m[2] !== '[REDACTED]') {
                    $found[] = rawurldecode($m[2]);
                    $found[] = $m[2];
                }
                return $m[1] . '[REDACTED]';
            },
            $s
        );
    }

    /**
     * LOG-SCRUB-01 / LOG-REDACT-01: the response body as it may be logged. JSON is decoded,
     * scrubbed and re-encoded; a body that cannot be re-encoded is not logged (never the
     * raw body — it is what carries the secret). Then secret parameters are cut from
     * what remains (non-JSON bodies, URLs under other keys). Every value removed is added
     * to $found.
     *
     * @internal public for unit tests.
     */
    public static function redactResponse(?string $raw, array &$found = []): ?string
    {
        if ($raw === null || $raw === '') {
            return $raw;
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $encoded = json_encode(self::scrub($decoded, $found),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return is_string($encoded) ? $encoded : '{"redacted":"response not logged"}';
        }
        return self::scrubString($raw, $found);
    }

    private function truncate(?string $s, int $max): ?string
    {
        if ($s === null) return null;
        return strlen($s) > $max ? substr($s, 0, $max) . '…' : $s;
    }

    /**
     * SEC-L01: strip known-PII and secret query-string parameters from a URL
     * before it is written to the WHMCS module log.
     */
    private function redactUrl(string $url): string
    {
        return preg_replace(
            '/([?&])(search|email|token|key|password|secret)=([^&]*)/i',
            '$1$2=[REDACTED]',
            $url
        );
    }

    /**
     * SEC-L02: return true if the IPv4 address falls within a private, loopback,
     * link-local, or shared-address-space range (RFC 1918 / RFC 5735 / RFC 6598).
     */
    private static function isPrivateIp(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) return true; // unparseable — block to be safe
        foreach ([
            ['10.0.0.0',    8],  // RFC 1918 private
            ['172.16.0.0',  12], // RFC 1918 private
            ['192.168.0.0', 16], // RFC 1918 private
            ['127.0.0.0',   8],  // loopback
            ['169.254.0.0', 16], // link-local
            ['0.0.0.0',     8],  // "this" network
            ['100.64.0.0',  10], // RFC 6598 shared address space
        ] as [$subnet, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($long & $mask) === (ip2long($subnet) & $mask)) return true;
        }
        return false;
    }
}
}
