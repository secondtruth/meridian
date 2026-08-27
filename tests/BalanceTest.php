<?php

declare(strict_types=1);

namespace Meridian\Tests;

use Meridian\Account\ArticleRef;
use Meridian\Registry\Rating;
use Meridian\Registry\Registry;
use Meridian\Registry\Source;
use Meridian\Spectrum\Balance;
use Meridian\Spectrum\BalanceReport;
use Meridian\Spectrum\Distribution;
use PHPUnit\Framework\TestCase;

final class BalanceTest extends TestCase
{
    private static function source(string $id, string $perspective, float $economic, int $reliability = 4): Source
    {
        return new Source(
            id: $id, name: $id, country: 'DE', languages: ['de'],
            type: 'online', ownership: 'private', publisher: $id, funding: [],
            perspective: $perspective, topics: ['general'], homepage: '', feeds: ['https://x/rss'],
            rating: new Rating($economic, 0.0, 0.0, 'nonpartisan', $reliability, 'high', 'none', 'high'),
        );
    }

    private static function registry(): Registry
    {
        return new Registry([
            self::source('links', 'dach', -2.5, 5),
            self::source('mitte', 'dach', 0.0, 3),
            self::source('europa', 'europe', 2.0),
            self::source('sued', 'global-south', 0.0),
        ]);
    }

    private static function read(string $sourceId, string $topic): ArticleRef
    {
        static $counter = 0;

        return new ArticleRef(
            url: 'https://example.org/' . ++$counter,
            title: 'title',
            sourceId: $sourceId,
            topic: $topic,
            at: new \DateTimeImmutable('2026-08-05 12:00:00'),
        );
    }

    private static function report(): BalanceReport
    {
        return Balance::of([
            self::read('links', 'climate'),
            self::read('links', 'climate'),
            self::read('mitte', 'peace'),
            self::read('europa', 'peace'),
        ], self::registry(), 30);
    }

    private static function slice(Distribution $distribution, string $key): \Meridian\Spectrum\Slice
    {
        foreach ($distribution->slices as $slice) {
            if ($slice->key === $key) {
                return $slice;
            }
        }

        self::fail("no slice {$key} in {$distribution->axis}");
    }

    public function testReadSharesAreMeasuredAgainstTheDatasetComposition(): void
    {
        $perspectives = self::report()->perspectives;

        $dach = self::slice($perspectives, 'dach');
        self::assertSame(3, $dach->read);
        self::assertSame(75.0, $dach->readShare);
        self::assertSame(50.0, $dach->offerShare);
        self::assertSame(25.0, $dach->gap());
        self::assertTrue($dach->isOverweight());
    }

    public function testAnUnreadPartOfTheDatasetIsABlindspot(): void
    {
        $perspectives = self::report()->perspectives;

        self::assertTrue(self::slice($perspectives, 'global-south')->isBlindspot());
        self::assertSame(
            ['global-south'],
            array_map(static fn ($slice) => $slice->key, $perspectives->blindspots()),
        );
    }

    public function testAPerspectiveTheDatasetDoesNotCoverIsNoBlindspot(): void
    {
        $international = self::slice(self::report()->perspectives, 'international');

        self::assertSame(0, $international->read);
        self::assertSame(0.0, $international->offerShare);
        self::assertFalse($international->isBlindspot());
    }

    public function testTopicsHaveNoReferenceShareButStillReportGaps(): void
    {
        $topics = self::report()->topics;

        self::assertNull(self::slice($topics, 'climate')->offerShare);
        self::assertNull(self::slice($topics, 'climate')->gap());
        self::assertTrue(self::slice($topics, 'digital-rights')->isBlindspot());
        self::assertTrue(self::slice($topics, 'accessibility')->isBlindspot());
        self::assertFalse(self::slice($topics, 'peace')->isBlindspot());
    }

    public function testTheStrongestLeanIsNamed(): void
    {
        self::assertSame('dach', self::report()->perspectives->mostOverweight()?->key);
    }

    public function testABalancedReadingHasNoLean(): void
    {
        $report = Balance::of([
            self::read('links', 'climate'),
            self::read('mitte', 'climate'),
            self::read('europa', 'peace'),
            self::read('sued', 'peace'),
        ], self::registry(), 30);

        self::assertNull($report->perspectives->mostOverweight());
        self::assertSame([], $report->perspectives->blindspots());
    }

    public function testEconomicBandsUseTheSameBucketsAsTheRestOfTheSite(): void
    {
        $economic = self::report()->economic;

        self::assertCount(5, $economic->slices);
        self::assertSame(2, self::slice($economic, '0')->read);   // −2.5 → left
        self::assertSame(1, self::slice($economic, '2')->read);   //   0.0 → centre
        self::assertSame(0, self::slice($economic, '3')->read);
        self::assertSame(1, self::slice($economic, '4')->read);   // +2.0 → right
    }

    public function testMostReadSourcesComeFirst(): void
    {
        $sources = self::report()->sources;

        self::assertSame('links', $sources[0]['id']);
        self::assertSame(2, $sources[0]['count']);
        self::assertNotNull($sources[0]['source']);
    }

    public function testAverageReliabilityIgnoresSourcesThatLeftTheDataset(): void
    {
        $report = Balance::of([
            self::read('links', 'climate'),   // reliability 5
            self::read('mitte', 'climate'),   // reliability 3
            self::read('vanished', 'climate'),
        ], self::registry(), 30);

        self::assertSame(3, $report->total);
        self::assertSame(4.0, $report->averageReliability);
        self::assertSame(2, array_sum(array_map(
            static fn ($slice) => $slice->read,
            $report->perspectives->slices,
        )));
    }

    public function testAReaderWithoutHistoryGetsAnEmptyReport(): void
    {
        $report = Balance::of([], self::registry(), 7);

        self::assertTrue($report->isEmpty());
        self::assertNull($report->averageReliability);
        self::assertTrue($report->perspectives->isEmpty());
        self::assertSame(0.0, self::slice($report->perspectives, 'dach')->readShare);
    }
}
