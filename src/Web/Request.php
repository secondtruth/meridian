<?php

declare(strict_types=1);

namespace Meridian\Web;

use Symfony\Component\HttpFoundation\Request as HttpRequest;

/**
 * One incoming HTTP request, decoupled from PHP's superglobals.
 *
 * The value object the handlers see stays Meridian's own; the parsing of
 * the raw request (method, path, scheme, host, forwarded headers) is
 * delegated to symfony/http-foundation in {@see fromGlobals()}, which
 * handles the edge cases a hand-rolled parser gets wrong.
 */
final readonly class Request
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $cookies
     * @param \DateTimeImmutable    $now     the request's single "now" — every age, window and
     *                                      timestamp below derives from it, so a test can
     *                                      pick the moment
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $body = [],
        public array $cookies = [],
        public string $host = 'localhost',
        public bool $secure = false,
        public \DateTimeImmutable $now = new \DateTimeImmutable(),
    ) {
    }

    public static function fromGlobals(): self
    {
        // Forwarded headers (X-Forwarded-Proto/-Host/-For) are only honored
        // when they come from a trusted peer. Default: the immediate client
        // (REMOTE_ADDR), which matches a reverse-proxy-in-front deployment;
        // a hardened setup pins explicit proxy addresses via
        // MERIDIAN_TRUSTED_PROXIES (comma-separated IPs/CIDRs).
        $proxies = getenv('MERIDIAN_TRUSTED_PROXIES');
        HttpRequest::setTrustedProxies(
            is_string($proxies) && $proxies !== ''
                ? array_map(trim(...), explode(',', $proxies))
                : ['REMOTE_ADDR'],
            HttpRequest::HEADER_X_FORWARDED_FOR
                | HttpRequest::HEADER_X_FORWARDED_HOST
                | HttpRequest::HEADER_X_FORWARDED_PROTO
                | HttpRequest::HEADER_X_FORWARDED_PORT,
        );

        $http = HttpRequest::createFromGlobals();

        return new self(
            method: $http->getMethod(),
            path: $http->getPathInfo(),
            query: $http->query->all(),
            body: $http->request->all(),
            cookies: array_map(strval(...), $http->cookies->all()),
            host: $http->getHttpHost(),
            secure: $http->isSecure(),
        );
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /** Path without a trailing slash, "/" for the root. */
    public function normalizedPath(): string
    {
        return rtrim($this->path, '/') ?: '/';
    }

    public function query(string $key): ?string
    {
        return isset($this->query[$key]) ? (string) $this->query[$key] : null;
    }

    public function input(string $key): ?string
    {
        return isset($this->body[$key]) && is_scalar($this->body[$key])
            ? (string) $this->body[$key]
            : null;
    }

    public function cookie(string $key): ?string
    {
        return $this->cookies[$key] ?? null;
    }

    public function origin(): string
    {
        return ($this->secure ? 'https://' : 'http://') . $this->host;
    }
}
