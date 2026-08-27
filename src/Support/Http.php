<?php

declare(strict_types=1);

namespace Meridian\Support;

/**
 * Minimal JSON client for the OIDC back channel. Unlike the feed
 * fetcher this never follows redirects and never tolerates a failure:
 * an identity provider that answers oddly must not silently produce a
 * half-verified login.
 */
class Http
{
    private const TIMEOUT_SECONDS = 10;
    private const USER_AGENT = 'Meridian/0.1 (prototype news aggregator)';

    /** @return array<string, mixed> */
    public function getJson(string $url): array
    {
        return $this->decode($this->send($url, null), $url);
    }

    /**
     * @param array<string, string>      $form
     * @param array<string, string>|null $basicAuth [user, password] pair
     *
     * @return array<string, mixed>
     */
    public function postForm(string $url, array $form, ?array $basicAuth = null): array
    {
        return $this->decode($this->send($url, http_build_query($form), $basicAuth), $url);
    }

    /** @param array<string, string>|null $basicAuth */
    protected function send(string $url, ?string $body, ?array $basicAuth = null): string
    {
        $handle = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
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
            throw new \RuntimeException("request to {$url} answered HTTP {$status}: " . substr($response, 0, 200));
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
