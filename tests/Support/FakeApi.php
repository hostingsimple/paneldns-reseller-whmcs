<?php

/**
 * PanelDnsResellerApi with the network replaced by a route table.
 *
 * Everything above transport() — status/ok handling, error parsing, Retry-After,
 * meta, paging — is the production code.
 */
final class FakeApi extends PanelDnsResellerApi
{
    /** @var list<array{method:string, path:string, query:array, body:?array}> */
    public array $calls = [];

    /** @var list<array{0:string,1:string,2:mixed}> */
    private array $routes = [];

    public function __construct(string $token = '')
    {
        parent::__construct(
            'https://paneldns.test',
            $token !== '' ? $token : 'test-' . bin2hex(random_bytes(6)),
            self::MODE_RESELLER
        );
    }

    /**
     * Register a response for METHOD path. $response is a transport array (see json())
     * or a Closure(array $query, ?array $body): array. Later registrations win.
     */
    public function on(string $method, string $path, $response): self
    {
        $this->routes[] = [strtoupper($method), $path, $response];
        return $this;
    }

    public static function json(int $status, $body, array $headers = []): array
    {
        return [
            'status'     => $status,
            'body'       => is_string($body) ? $body : json_encode($body),
            'headers'    => array_change_key_case($headers, CASE_LOWER),
            'curl_error' => '',
            'curl_errno' => 0,
            'primary_ip' => '203.0.113.10',
        ];
    }

    /** @return list<array> calls matching METHOD path */
    public function callsTo(string $method, string $path): array
    {
        return array_values(array_filter(
            $this->calls,
            fn ($c) => $c['method'] === strtoupper($method) && $c['path'] === $path
        ));
    }

    protected function transport(string $method, string $url, ?array $body): array
    {
        $parts = parse_url($url);
        $path  = $parts['path'] ?? '/';
        parse_str($parts['query'] ?? '', $query);
        $this->calls[] = ['method' => strtoupper($method), 'path' => $path, 'query' => $query, 'body' => $body];

        foreach (array_reverse($this->routes) as [$m, $p, $resp]) {
            if ($m === strtoupper($method) && $p === $path) {
                return $resp instanceof \Closure ? $resp($query, $body) : $resp;
            }
        }
        return self::json(404, ['ok' => false, 'error' => 'Not found.', 'code' => 404]);
    }
}
