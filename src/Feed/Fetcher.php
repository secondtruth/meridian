<?php

declare(strict_types=1);

namespace Meridian\Feed;

use Meridian\Registry\Registry;
use Meridian\Support\Http;

/**
 * Downloads all feeds of all sources. Individual feed failures are
 * collected, never fatal — a daily edition with fewer sources beats no
 * edition. Intended to run from cron; the web app only reads the cache.
 */
final class Fetcher
{
    public function __construct(
        private readonly FeedParser $parser = new FeedParser(),
        private readonly Http $http = new Http(timeoutSeconds: 20, followRedirects: true),
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
                    $parsed = $this->parser->parse($this->http->get($feedUrl));
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
}
