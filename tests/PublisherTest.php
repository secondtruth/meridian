<?php

declare(strict_types=1);

namespace Meridian\Tests;

use Meridian\Registry\Rating;
use Meridian\Registry\Registry;
use Meridian\Registry\Source;
use PHPUnit\Framework\TestCase;

final class PublisherTest extends TestCase
{
    private static function source(
        string $id,
        string $publisher,
        string $country,
        float $economic,
        int $reliability,
        ?string $wikidata = null,
    ): Source
    {
        return new Source(
            id: $id, name: $id, country: $country, languages: ['de'],
            type: 'online', ownership: 'private', publisher: $publisher, funding: ['donations'],
            perspective: 'dach', topics: ['general'], homepage: '', feeds: ['https://x/rss'],
            rating: new Rating($economic, 0.0, 0.0, 'nonpartisan', $reliability, 'high', 'none', 'high'),
            wikidata: $wikidata,
        );
    }

    public function testSourcesAreGroupedByPublisherConcentrationFirst(): void
    {
        $registry = new Registry([
            self::source('leidmedien', 'SOZIALHELDEN e. V.', 'DE', -1.0, 4),
            self::source('welt', 'Axel Springer SE', 'DE', 2.0, 4),
            self::source('neue-norm', 'SOZIALHELDEN e. V.', 'DE', -1.0, 4),
        ]);

        $publishers = $registry->publishers();

        self::assertCount(2, $publishers);
        // The multi-outlet house sorts first.
        self::assertSame('SOZIALHELDEN e. V.', $publishers[0]->name);
        self::assertTrue($publishers[0]->isGroup());
        self::assertSame(2, $publishers[0]->outletCount());
        self::assertFalse($publishers[1]->isGroup());
        self::assertSame('Axel Springer SE', $publishers[1]->name);
    }

    public function testAggregatesAveragesAndReliabilityRange(): void
    {
        $registry = new Registry([
            self::source('a', 'House', 'DE', -2.0, 3),
            self::source('b', 'House', 'AT', 0.0, 5),
        ]);

        $house = $registry->publishers()[0];

        self::assertSame(-1.0, $house->averageEconomic());
        self::assertSame([-2.0, 0.0], [$house->outlets[0]->rating->economic, $house->outlets[1]->rating->economic]);
        self::assertSame(['DE', 'AT'], $house->countries());
        self::assertSame(3, $house->minReliability());
        self::assertSame(5, $house->maxReliability());
    }

    public function testValidatorFlagsMissingPublisher(): void
    {
        $registry = new Registry([
            new Source(
                id: 'x', name: 'X', country: 'DE', languages: ['de'],
                type: 'online', ownership: 'private', publisher: '', funding: ['donations'],
                perspective: 'dach', topics: ['general'], homepage: '', feeds: ['https://x/rss'],
                rating: new Rating(0.0, 0.0, 0.0, 'nonpartisan', 4, 'high', 'none', 'high'),
            ),
        ]);

        $problems = $registry->validate();

        self::assertNotEmpty(array_filter($problems, fn (string $p): bool => str_contains($p, 'missing publisher')));
    }

    public function testWikidataQidIsLoadedAndLinked(): void
    {
        $source = Source::fromArray([
            'id' => 'taz',
            'wikidata' => 'Q161423',
        ]);

        self::assertSame('Q161423', $source->wikidata);
        self::assertSame('https://www.wikidata.org/wiki/Q161423', $source->wikidataUrl());
    }

    public function testValidatorRejectsMalformedAndDuplicateWikidataQids(): void
    {
        $registry = new Registry([
            self::source('a', 'House A', 'DE', 0.0, 4, 'Q161423'),
            self::source('b', 'House B', 'DE', 0.0, 4, 'Q161423'),
            self::source('c', 'House C', 'DE', 0.0, 4, 'https://www.wikidata.org/wiki/Q29872'),
        ]);

        $problems = $registry->validate();

        self::assertNotEmpty(array_filter($problems, fn (string $p): bool => str_contains($p, 'already assigned to a')));
        self::assertNotEmpty(array_filter($problems, fn (string $p): bool => str_contains($p, 'canonical QID')));
    }
}
