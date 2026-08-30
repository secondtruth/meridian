<?php

declare(strict_types=1);

namespace Meridian\Web;

use Symfony\Component\HttpFoundation\Cookie as HttpCookie;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * An outgoing HTTP response — built by the app, emitted by the front
 * controller. The value object stays Meridian's own; the actual emission
 * (status line, header normalization, cookie serialization) is delegated
 * to symfony/http-foundation in {@see send()}.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     * @param list<Cookie>          $cookies
     */
    private function __construct(
        public readonly int $status,
        public readonly string $body,
        private array $headers = [],
        private array $cookies = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            $status,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    /** @param array<string, mixed> $data */
    public static function jsonDownload(array $data, string $filename): self
    {
        return self::json($data)->withHeader('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self($status, '', ['Location' => $location]);
    }

    public static function noContent(): self
    {
        return new self(204, '');
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function withCookie(Cookie $cookie): self
    {
        $this->cookies[] = $cookie;

        return $this;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @return list<Cookie> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function send(): void
    {
        $response = new HttpResponse($this->body, $this->status, $this->headers);
        foreach ($this->cookies as $cookie) {
            $response->headers->setCookie(new HttpCookie(
                name: $cookie->name,
                value: $cookie->value,
                expire: $cookie->expires,
                path: '/',
                secure: $cookie->secure,
                httpOnly: $cookie->httpOnly,
                sameSite: strtolower($cookie->sameSite),
            ));
        }
        $response->send();
    }
}
