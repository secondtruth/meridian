<?php

declare(strict_types=1);

namespace Meridian\Feed;

use Meridian\Registry\Source;
use Meridian\Support\Http;

/**
 * Mirrors source favicons into the public directory so cards can show
 * them without any third-party request (GDPR — same rule as the
 * self-hosted fonts). Icons are stored as `<source id>.<ext>` with the
 * extension taken from the bytes, never from the URL; a source whose
 * icon cannot be fetched simply has none — the text-first layout stands
 * on its own.
 */
final class FaviconMirror
{
    private const MAX_BYTES = 262144; // an icon, not an image gallery

    public function __construct(
        private readonly Http $http = new Http(timeoutSeconds: 15, followRedirects: true),
    ) {
    }

    /**
     * Fetches and stores one source's favicon. Returns the stored
     * filename, or null when nothing usable was found.
     */
    public function mirror(Source $source, string $dir): ?string
    {
        if ($source->homepage === '') {
            return null;
        }

        foreach ($this->candidateUrls($source->homepage) as $url) {
            try {
                $bytes = $this->http->get($url);
            } catch (\RuntimeException) {
                continue;
            }
            $extension = self::extensionFor($bytes);
            if ($extension === null || strlen($bytes) > self::MAX_BYTES) {
                continue;
            }
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $filename = "{$source->id}.{$extension}";
            file_put_contents("{$dir}/{$filename}", $bytes);

            return $filename;
        }

        return null;
    }

    /**
     * Icon URLs to try, best first: whatever the homepage's markup
     * declares, then the /favicon.ico convention.
     *
     * @return list<string>
     */
    private function candidateUrls(string $homepage): array
    {
        $urls = [];
        try {
            $declared = self::iconUrl($this->http->get($homepage), $homepage);
            if ($declared !== null) {
                $urls[] = $declared;
            }
        } catch (\RuntimeException) {
            // no homepage, no declared icon — the convention below remains
        }
        $urls[] = self::resolveUrl('/favicon.ico', $homepage);

        return $urls;
    }

    /** First `<link rel=…icon…>` of an HTML document, resolved against the page URL. */
    public static function iconUrl(string $html, string $pageUrl): ?string
    {
        if (preg_match_all('/<link\b[^>]*>/i', $html, $matches) === 0) {
            return null;
        }
        foreach ($matches[0] as $tag) {
            if (preg_match('/\brel\s*=\s*["\']?([^"\'>]*)/i', $tag, $rel) !== 1
                || !str_contains(strtolower($rel[1]), 'icon')) {
                continue;
            }
            if (preg_match('/\bhref\s*=\s*["\']?([^"\'\s>]+)/i', $tag, $href) === 1) {
                return self::resolveUrl(html_entity_decode($href[1]), $pageUrl);
            }
        }

        return null;
    }

    /** Resolves a possibly relative icon href against the page it came from. */
    public static function resolveUrl(string $href, string $pageUrl): string
    {
        if (preg_match('#\Ahttps?://#i', $href) === 1) {
            return $href;
        }
        $parts = parse_url($pageUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        if (str_starts_with($href, '//')) {
            return "{$scheme}:{$href}";
        }
        if (str_starts_with($href, '/')) {
            return "{$scheme}://{$host}{$href}";
        }
        $path = $parts['path'] ?? '/';
        $base = substr($path, 0, strrpos($path, '/') + 1) ?: '/';

        return "{$scheme}://{$host}{$base}{$href}";
    }

    /** File extension by content sniffing — the URL's word counts for nothing. */
    public static function extensionFor(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'png',
            str_starts_with($bytes, "\x00\x00\x01\x00") => 'ico',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'jpg',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'gif',
            strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' => 'webp',
            str_contains(substr($bytes, 0, 1024), '<svg') => 'svg',
            default => null,
        };
    }
}
