<?php

declare(strict_types=1);

namespace Meridian\Tests;

use Meridian\Collection\Duel;
use Meridian\Edition\Article;
use Meridian\Feed\Item;
use Meridian\Registry\Rating;
use Meridian\Registry\Source;
use PHPUnit\Framework\TestCase;

final class DuelTest extends TestCase
{
    private static function source(string $id, float $economic): Source
    {
        return new Source(
            id: $id, name: $id, country: 'DE', languages: ['de'],
            type: 'online', ownership: 'private', publisher: $id, funding: [],
            perspective: 'dach', topics: ['general'], homepage: '', feeds: [],
            rating: new Rating($economic, 0.0, 0.0, 'nonpartisan', 4, 'high', 'none', 'high'),
        );
    }

    private static function story(float $primaryEconomic, array $memberEconomics, string $suffix = ''): Article
    {
        $members = [];
        foreach ($memberEconomics as $i => $economic) {
            $members[] = new Article(
                new Item("m{$i}{$suffix}", 'telling', "https://x/m{$i}{$suffix}", '', new \DateTimeImmutable()),
                self::source("m{$i}{$suffix}", $economic),
                'climate',
            );
        }

        return new Article(
            new Item("p{$suffix}", 'story', "https://x/p{$suffix}", '', new \DateTimeImmutable()),
            self::source("p{$suffix}", $primaryEconomic),
            'climate',
            $members,
        );
    }

    public function testStoriesTwoBandsApartQualifyWithOutermostTellings(): void
    {
        $rows = Duel::select(['climate' => [self::story(-2.0, [0.0, 2.0])]]);

        self::assertCount(1, $rows);
        self::assertSame('p', $rows[0]['left']->source->id, 'leftmost telling by economic value');
        self::assertSame('m1', $rows[0]['right']->source->id, 'rightmost telling by economic value');
    }

    public function testNarrowStoriesAndSingleTellingsDoNotQualify(): void
    {
        $narrow = self::story(-1.0, [0.0], 'n');   // bands 1 and 2 — one apart
        $single = self::story(-2.0, [], 's');      // no second telling at all

        self::assertSame([], Duel::select(['climate' => [$narrow, $single]]));
    }

    public function testCapHolds(): void
    {
        $stories = [];
        for ($i = 0; $i < Duel::CAP + 3; ++$i) {
            $stories[] = self::story(-2.0, [2.0], (string) $i);
        }

        self::assertCount(Duel::CAP, Duel::select(['climate' => $stories]));
    }
}
