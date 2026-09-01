<?php

declare(strict_types=1);

namespace Meridian\Tests;

use Meridian\Edition\Article;
use Meridian\Edition\StoryClusterer;
use Meridian\Feed\Item;
use Meridian\Registry\Rating;
use Meridian\Registry\Source;
use PHPUnit\Framework\TestCase;

/** The matching rule of rating-system.md §5, pinned on its own. */
final class StoryClustererTest extends TestCase
{
    private static function article(string $sourceId, string $title, string $link): Article
    {
        $source = new Source(
            id: $sourceId, name: strtoupper($sourceId), country: 'DE', languages: ['de'],
            type: 'online', ownership: 'private', publisher: $sourceId, funding: [],
            perspective: 'dach', topics: ['general'], homepage: '', feeds: ['https://x/feed'],
            rating: new Rating(0.0, 0.0, 0.0, 'nonpartisan', 4, 'high', 'none', 'high'),
        );

        return new Article(new Item($sourceId, $title, $link, '', new \DateTimeImmutable()), $source, 'general');
    }

    public function testNearIdenticalHeadlinesJoinTheFreshestTelling(): void
    {
        $clustered = new StoryClusterer()->cluster([
            self::article('a', 'Nato-Gipfel beschließt neue Klimaschutz-Ziele für Streitkräfte', 'https://x/a/1'),
            self::article('b', 'Nato-Gipfel beschließt neue Klimaschutz-Ziele für die Streitkräfte', 'https://x/b/1'),
            self::article('c', 'Dürre bedroht Ernte in Südeuropa', 'https://x/c/1'),
        ]);

        self::assertCount(2, $clustered);
        self::assertSame('a', $clustered[0]->source->id, 'the first (freshest) telling leads');
        self::assertSame(['b'], array_map(fn (Article $t) => $t->source->id, $clustered[0]->alsoCoveredBy));
        self::assertSame([], $clustered[1]->alsoCoveredBy);
    }

    public function testSharedWordsBelowTheThresholdStaySeparateStories(): void
    {
        $clustered = new StoryClusterer()->cluster([
            self::article('a', 'Nato-Gipfel beschließt neue Klimaschutz-Ziele', 'https://x/a/1'),
            self::article('b', 'Nato-Gipfel: Streit über Verteidigungsausgaben eskaliert', 'https://x/b/1'),
        ]);

        self::assertCount(2, $clustered);
    }

    public function testASecondTellingFromTheSameSourceIsDroppedNotAttached(): void
    {
        $clustered = new StoryClusterer()->cluster([
            self::article('a', 'Nato-Gipfel beschließt neue Klimaschutz-Ziele für Streitkräfte', 'https://x/a/1'),
            self::article('a', 'Nato-Gipfel beschließt neue Klimaschutz-Ziele für die Streitkräfte', 'https://x/a/2'),
        ]);

        self::assertCount(1, $clustered);
        self::assertSame([], $clustered[0]->alsoCoveredBy);
    }

    public function testArticlesWithoutMatchesComeBackUntouched(): void
    {
        $lone = self::article('a', 'Dürre bedroht Ernte', 'https://x/a/1');

        self::assertSame([$lone], new StoryClusterer()->cluster([$lone]));
        self::assertSame([], new StoryClusterer()->cluster([]));
    }
}
