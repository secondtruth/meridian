<?php

declare(strict_types=1);

namespace Meridian\Web;

/** One incoming HTTP request, decoupled from PHP's superglobals. */
final readonly class Request
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $cookies
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $body = [],
        public array $cookies = [],
        public string $host = 'localhost',
        public bool $secure = false,
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $secure = ($_SERVER['HTTPS'] ?? '') !== ''
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        return new self(
            method: strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            path: parse_url($uri, PHP_URL_PATH) ?: '/',
            query: $_GET,
            body: $_POST,
            cookies: array_map(strval(...), $_COOKIE),
            host: (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            secure: $secure,
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
