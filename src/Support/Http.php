<?php

declare(strict_types=1);

namespace Meridian\Support;

/**
 * The one curl wrapper: feeds, favicons and the OIDC back channel all
 * go through it, each with its own policy set at construction. The
 * back channel keeps the defaults — no redirects, a short timeout —
 * because an identity provider that answers oddly must not silently
 * produce a half-verified login; the crawlers follow redirects and wait
 * longer. Any failure is an exception; tolerance is the caller's call.
 */
class Http
{
    private const USER_AGENT = 'Meridian/0.1 (prototype news aggregator)';
    private const MAX_REDIRECTS = 5;

    public function __construct(
        private readonly int $timeoutSeconds = 10,
        private readonly bool $followRedirects = false,
    ) {
    }

    /** The raw body of a GET — a feed document, an HTML page, an icon. */
    public function get(string $url): string
    {
        return $this->send($url, null);
    }

    /** @return array<string, mixed> */
    public function getJson(string $url): array
    {
        return $this->decode($this->send($url, null, accept: 'application/json'), $url);
    }

    /**
     * @param array<string, string>      $form
     * @param array<string, string>|null $basicAuth [user, password] pair
     *
     * @return array<string, mixed>
     */
    public function postForm(string $url, array $form, ?array $basicAuth = null): array
    {
        return $this->decode($this->send($url, http_build_query($form), $basicAuth, 'application/json'), $url);
    }

    /** @param array<string, string>|null $basicAuth */
    protected function send(string $url, ?string $body, ?array $basicAuth = null, ?string $accept = null): string
    {
        $handle = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $this->followRedirects,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $accept === null ? [] : ["Accept: {$accept}"],
        ];
        if ($body !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        }
        if ($basicAuth !== null) {
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] = implode(':', $basicAuth);
        }
        curl_setopt_array($handle, $options);

        $response = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        if (!is_string($response)) {
            throw new \RuntimeException("request to {$url} failed: {$error}");
        }
        if ($status >= 400) {
            throw new \RuntimeException("request to {$url} answered HTTP {$status}");
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function decode(string $response, string $url): array
    {
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \RuntimeException("{$url} did not answer with a JSON object");
        }

        return $data;
    }
}
