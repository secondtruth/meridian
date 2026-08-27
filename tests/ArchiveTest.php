<?php

declare(strict_types=1);

namespace Meridian\Tests;

use Meridian\Edition\Archive;
use Meridian\Edition\Article;
use Meridian\Edition\Edition;
use Meridian\Edition\Mode;
use Meridian\Edition\Section;
use Meridian\Feed\Item;
use Meridian\Registry\Rating;
use Meridian\Registry\Source;
use Meridian\Spectrum\Diversity;
use PHPUnit\Framework\TestCase;

final class ArchiveTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/meridian-archive-' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private static function edition(string $date): Edition
    {
        $source = new Source(
            id: 'taz', name: 'taz — die tageszeitung', country: 'DE', languages: ['de'],
            type: 'newspaper', ownership: 'cooperative', publisher: 'taz', funding: [],
            perspective: 'dach', topics: ['general'], homepage: '', feeds: ['https://x/feed'],
            rating: new Rating(-2.0, -2.5, 0.5, 'green', 4, 'high', 'none', 'high'),
        );
        $telling = new Article(
            new Item('dlf', 'Klimakrise: Lagebericht', 'https://x/2', '', new \DateTimeImmutable($date . ' 07:00:00')),
            new Source(
                id: 'dlf', name: 'Deutschlandfunk', country: 'DE', languages: ['de'],
                type: 'public-broadcaster', ownership: 'public-service', publisher: 'Deutschlandradio', funding: [],
                perspective: 'dach', topics: ['general'], homepage: '', feeds: ['https://x/feed2'],
                rating: new Rating(0.0, -0.5, 1.0, 'nonpartisan', 5, 'high', 'indirect', 'high'),
            ),
            'climate',
        );
        $article = new Article(
            new Item('taz', 'Klimakrise: Bericht', 'https://x/1', 'Zusammenfassung', new \DateTimeImmutable($date . ' 08:00:00')),
            $source,
            'climate',
            [$telling],
        );

        return new Edition(
            new \DateTimeImmutable($date . ' 12:00:00'),
            Mode::Compact,
            [new Section('climate', [$article], new Diversity())],
        );
    }

    public function testSaveLoadRoundTrip(): void
    {
        $archive = new Archive($this->dir);

        $date = $archive->save(self::edition('2026-08-27'));

        self::assertSame('2026-08-27', $date);
        $data = $archive->load($date);
        self::assertNotNull($data);
        self::assertSame('2026-08-27', $data['date']);
        self::assertSame('climate', $data['sections'][0]['topic']);
        self::assertSame(
            ['source_id' => 'taz', 'source_name' => 'taz — die tageszeitung', 'title' => 'Klimakrise: Bericht'],
            array_intersect_key($data['sections'][0]['articles'][0], array_flip(['source_id', 'source_name', 'title'])),
        );
        // The story cluster survives the freeze (rating-system.md §6).
        self::assertSame('dlf', $data['sections'][0]['articles'][0]['also_covered_by'][0]['source_id']);
    }

    public function testDatesAreNewestFirstAndSameDayOverwrites(): void
    {
        $archive = new Archive($this->dir);
        $archive->save(self::edition('2026-08-25'));
        $archive->save(self::edition('2026-08-27'));
        $archive->save(self::edition('2026-08-27'));

        self::assertSame(['2026-08-27', '2026-08-25'], $archive->dates());
    }

    public function testLoadRejectsMalformedDatesAndMissingDays(): void
    {
        $archive = new Archive($this->dir);
        $archive->save(self::edition('2026-08-27'));

        self::assertNull($archive->load('2026-08-28'));
        self::assertNull($archive->load('../etc/passwd'));
        self::assertNull($archive->load('2026-8-27'));
    }
}
