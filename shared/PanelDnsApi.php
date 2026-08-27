<?php

// M-19: file guard — prevent direct web access outside WHMCS context.
if (!defined('WHMCS')) { die('Access denied.'); }

/**
 * PanelDnsResellerApi — HTTP client wrapping the PanelDNS Platform + Public APIs.
 *
 * Used by both paneldns-platform (operator tier, /platform/v1) and
 * paneldns-reseller (reseller tier, /api/v1) WHMCS server modules. The
 * tier is determined by the api_mode passed in the constructor and the
 * key supplied (PLATFORM_API_KEY-shaped vs Sanctum Bearer).
 *
 * Conventions:
 *   - No Composer, no namespaces (lives in shared/ symlinked into both modules).
 *   - cURL only (no Guzzle).
 *   - IPv4 only (CURLOPT_IPRESOLVE_V4) — SSRF guard, matches dns-whmcs.
 *   - TLS verify ON by default; per-server tls_verify flag honoured.
 *   - Returns ['ok' => bool, 'status' => int, 'data' => array|null, 'error' => string|null].
 *   - Redacts the API key from any logModuleCall payload.
 */

if (!defined('WHMCS_PANELDNS_API_VERSION')) {
    define('WHMCS_PANELDNS_API_VERSION', '0.1.0');
}

if (!class_exists('PanelDnsResellerApi', false)) {
class PanelDnsResellerApi
{
    const MODE_PLATFORM = 'platform';
    const MODE_RESELLER = 'reseller';

    /** @var string */ private $baseUrl;
    /** @var string */ private $apiKey;
    /** @var string */ private $mode;
    /** @var bool */   private $tlsVerify;
    /** @var int */    private $timeout = 15;

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
     * Returns up to $perPage results from the given page. Callers must
     * filter results client-side for an exact email match — the server uses
     * a LIKE query that may surface partial matches.
     *
     * Used by bulkSyncForServer() to dedup before creating sub-clients.
     */
    public function searchSubClients(string $email, int $page = 1, int $perPage = 50): array
    {
        return $this->get('/api/v1/sub-clients', [
            'search'   => $email,
            'page'     => $page,
            'per_page' => $perPage,
        ]);
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

    // ── Internals ─────────────────────────────────────────────────────────────

    private function request(string $method, string $url, ?array $body = null): array
    {
        $ch = curl_init();
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'User-Agent: paneldns-whmcs/' . WHMCS_PANELDNS_API_VERSION,
        ];

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
        ];

        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;

        curl_setopt_array($ch, $opts);
        $raw       = curl_exec($ch);
        $status    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $primaryIp = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $err       = curl_error($ch);
        // PHP8-CURL-01: the handle is intentionally left to be freed by refcount. The
        // explicit close has had no effect since PHP 8.0 and is deprecated in PHP 8.5,
        // where it emits a deprecation notice on EVERY API call. WHMCS 8.7, HostBill and
        // Blesta 5 all require PHP 8, so no supported platform still needs it.

        $this->logCall($method, $url, $body, $status, $raw, $err);

        if ($err) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => $err];
        }

        // SEC-L02: belt-and-braces SSRF guard — block responses that resolved to a
        // private/loopback IPv4 range even when CURLOPT_IPRESOLVE_V4 is set.
        if ($primaryIp !== '' && self::isPrivateIp($primaryIp)) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'SSRF guard: target resolved to a private IP address'];
        }

        $decoded = json_decode($raw ?: 'null', true);
        $ok = $status >= 200 && $status < 300 && is_array($decoded) && !empty($decoded['ok']);

        return [
            'ok'       => $ok,
            'status'   => $status,
            'data'     => is_array($decoded) ? ($decoded['data'] ?? $decoded) : null,
            'error'    => $ok ? null : ($decoded['error'] ?? "HTTP {$status}"),
            // EXPORT-01: raw body for non-JSON endpoints (e.g. zone BIND export returns
            // text/plain). Callers check 'status' directly and read 'raw_body'.
            'raw_body' => $raw,
        ];
    }

    private function logCall(string $method, string $url, ?array $body, int $status, ?string $rawResponse, ?string $curlError): void
    {
        if (!function_exists('logModuleCall')) return;

        // Redact the API key from the URL (none in URL, only in header) — and from any logged body.
        // SEC-L01: also strip PII query params (search/email) from the logged URL.
        $bodyRedacted = $this->redact($body ?? []);

        logModuleCall(
            'paneldns',
            "{$method} " . $this->redactUrl($url),
            $bodyRedacted,
            ['status' => $status, 'response' => $this->truncate($rawResponse, 4096), 'curl_error' => $curlError],
            null,
            ['api_key', 'authorization', 'password', 'secret', 'token']
        );
    }

    private function redact(array $payload): array
    {
        $copy = $payload;
        foreach (['password', 'api_key', 'token', 'secret', 'authorization', 'access_hash'] as $key) {
            if (isset($copy[$key])) $copy[$key] = '[REDACTED]';
        }
        return $copy;
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
