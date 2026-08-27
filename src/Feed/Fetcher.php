<?php

declare(strict_types=1);

namespace Meridian\Feed;

use Meridian\Registry\Registry;

/**
 * Downloads all feeds of all sources. Individual feed failures are
 * collected, never fatal — a daily edition with fewer sources beats no
 * edition. Intended to run from cron; the web app only reads the cache.
 */
final class Fetcher
{
    private const USER_AGENT = 'Meridian/0.1 (prototype news aggregator)';
    private const TIMEOUT_SECONDS = 20;

    public function __construct(
        private readonly FeedParser $parser = new FeedParser(),
    ) {
    }

    /**
     * @param callable(string, string): void|null $onError called with (feedUrl, message)
     *
     * @return array{items: list<Item>, failed: list<string>}
     */
    public function fetchAll(Registry $registry, ?callable $onError = null): array
    {
        $items = [];
        $failed = [];

        foreach ($registry->all() as $source) {
            foreach ($source->feeds as $feedUrl) {
                try {
                    $parsed = $this->parser->parse($this->download($feedUrl));
                } catch (\RuntimeException $e) {
                    $failed[] = $feedUrl;
                    if ($onError !== null) {
                        $onError($feedUrl, $e->getMessage());
                    }
                    continue;
                }
                foreach ($parsed as $item) {
                    $items[] = new Item(
                        sourceId: $source->id,
                        title: $item->title,
                        link: $item->link,
                        summary: $item->summary,
                        published: $item->published,
                    );
                }
            }
        }

        return ['items' => $items, 'failed' => $failed];
    }

    private function download(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        if ($body === false) {
            throw new \RuntimeException("download failed: {$error}");
        }
        if ($status >= 400) {
            throw new \RuntimeException("HTTP {$status}");
        }

        return $body;
    }
}
