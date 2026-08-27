<?php

declare(strict_types=1);

namespace Meridian\Tests;

use Meridian\Collection\Collection;
use Meridian\Collection\Collections;
use Meridian\Collection\Selector;
use Meridian\Edition\Builder;
use Meridian\Feed\Item;
use Meridian\Registry\Rating;
use Meridian\Registry\Registry;
use Meridian\Registry\Source;
use PHPUnit\Framework\TestCase;

final class CollectionsTest extends TestCase
{
    private static function source(string $id, bool $edition = true): Source
    {
        return new Source(
            id: $id, name: $id, country: 'DE', languages: ['de'],
            type: 'online', ownership: 'private', publisher: $id, funding: [],
            perspective: 'dach', topics: ['general'], homepage: '', feeds: ['https://x/feed'],
            rating: new Rating(0.0, 0.0, 0.0, 'nonpartisan', 4, 'high', 'none', 'high'),
            edition: $edition,
        );
    }

    private static function item(string $sourceId, string $title, string $link, string $published): Item
    {
        return new Item($sourceId, $title, $link, '', new \DateTimeImmutable($published));
    }

    private static function collection(
        array $sources,
        int $windowDays = 7,
        int $cap = 12,
        int $perSource = 4,
    ): Collection {
        return new Collection('test', $sources, $windowDays, $cap, $perSource);
    }

    public function testShippedCollectionsAreValidAgainstTheDataset(): void
    {
        $registry = Registry::load(__DIR__ . '/../data/sources');
        $collections = Collections::load(__DIR__ . '/../data/collections.yaml');

        self::assertSame([], $collections->validate($registry));
        self::assertGreaterThanOrEqual(4, $collections->count());
    }

    public function testUnknownSourceIsReported(): void
    {
        $registry = new Registry([self::source('a')]);
        $collections = new Collections([self::collection(['a', 'ghost'])]);

        $problems = $collections->validate($registry);

        self::assertSame(['test: unknown source "ghost"'], $problems);
    }

    public function testCapAndWindowRangesAreEnforced(): void
    {
        $registry = new Registry([self::source('a')]);
        $collections = new Collections([self::collection(['a'], windowDays: 0, cap: 30, perSource: 31)]);

        $problems = $collections->validate($registry);

        self::assertCount(3, $problems);
    }

    public function testSelectorRespectsWindowPerSourceAndCap(): void
    {
        $registry = new Registry([self::source('a'), self::source('b')]);
        $collection = self::collection(['a', 'b'], windowDays: 7, cap: 3, perSource: 2);
        $now = new \DateTimeImmutable('2026-08-27 12:00:00');

        $items = [
            self::item('a', 'A1', 'https://x/a1', '2026-08-26 10:00:00'),
            self::item('a', 'A2', 'https://x/a2', '2026-08-25 10:00:00'),
            self::item('a', 'A3 over per-source', 'https://x/a3', '2026-08-24 10:00:00'),
            self::item('b', 'B1', 'https://x/b1', '2026-08-26 18:00:00'),
            self::item('b', 'B2 over cap', 'https://x/b2', '2026-08-23 10:00:00'),
            self::item('b', 'B0 too old', 'https://x/b0', '2026-08-01 10:00:00'),
            self::item('c', 'C not a member', 'https://x/c1', '2026-08-26 10:00:00'),
        ];

        $selected = (new Selector())->select($collection, $registry, $items, $now);

        self::assertSame(
            ['https://x/b1', 'https://x/a1', 'https://x/a2'],
            array_map(fn ($a) => $a->item->link, $selected),
            'newest first, at most 2 per source, capped at 3',
        );
    }

    public function testSelectorDropsDuplicateLinks(): void
    {
        $registry = new Registry([self::source('a')]);
        $collection = self::collection(['a']);
        $now = new \DateTimeImmutable('2026-08-27 12:00:00');

        $items = [
            self::item('a', 'Same', 'https://x/same', '2026-08-26 10:00:00'),
            self::item('a', 'Same again', 'https://x/same', '2026-08-25 10:00:00'),
        ];

        $selected = (new Selector())->select($collection, $registry, $items, $now);

        self::assertCount(1, $selected);
    }

    public function testCollectionOnlySourcesStayOutOfTheEdition(): void
    {
        // edition: false keeps a collection source's items out of the
        // topic classification entirely — kids' news must not compete in
        // the peace section.
        $registry = new Registry([self::source('logo-test', edition: false)]);
        $items = [self::item('logo-test', 'Krieg einfach erklärt', 'https://x/k1', 'now')];

        $byTopic = (new Builder())->classifyFresh($registry, $items, new \DateTimeImmutable());

        self::assertSame([], $byTopic);
    }
}
