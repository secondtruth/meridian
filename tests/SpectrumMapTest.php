<?php

declare(strict_types=1);

namespace Meridian\Tests;

use Meridian\Registry\Rating;
use Meridian\Registry\Source;
use Meridian\Web\SpectrumMap;
use PHPUnit\Framework\TestCase;

final class SpectrumMapTest extends TestCase
{
    private static function source(string $id, float $economic, float $cultural, int $reliability = 4): Source
    {
        return new Source(
            id: $id, name: ucfirst($id), country: 'DE', languages: ['de'],
            type: 'online', ownership: 'private', publisher: $id, funding: [],
            perspective: 'dach', topics: ['general'], homepage: '', feeds: [],
            rating: new Rating($economic, $cultural, 0.0, 'nonpartisan', $reliability, 'high', 'none', 'high'),
        );
    }

    public function testIdenticallyRatedSourcesNoLongerShareOneDot(): void
    {
        $points = SpectrumMap::points([
            self::source('a', -0.5, 1.0),
            self::source('b', -0.5, 1.0),
            self::source('c', -0.5, 1.0),
        ]);

        $seen = [];
        foreach ($points as $p) {
            $seen[] = sprintf('%.1f:%.1f', $p['cx'], $p['cy']);
        }
        self::assertCount(3, array_unique($seen));

        // Spread stays local: every dot within a ring around the shared position.
        foreach ($points as $p) {
            self::assertEqualsWithDelta(50.0 + 2.5 / 6.0 * 640.0, $p['cx'], 20.0);
            self::assertEqualsWithDelta(30.0 + 2.0 / 6.0 * 380.0, $p['cy'], 20.0);
        }
    }

    public function testIsolatedSourceKeepsItsLabelAndNoNumber(): void
    {
        [$p] = SpectrumMap::points([self::source('alone', 2.0, -2.0)]);

        self::assertNull($p['number']);
        self::assertSame('start', $p['anchor']);
        self::assertGreaterThan($p['cx'], $p['lx']);
    }

    public function testCrowdedSourcesFallBackToSequentialNumbers(): void
    {
        // A 5 × 5 grid 0.2 rating apart is ~21 px × 13 px per cell: too
        // tight for 12 px labels next to 14 px dots.
        $sources = [];
        for ($x = 0; $x < 5; ++$x) {
            for ($y = 0; $y < 5; ++$y) {
                $sources[] = self::source("crowd{$x}{$y}", -0.4 + $x * 0.2, -0.4 + $y * 0.2, 3);
            }
        }
        $points = SpectrumMap::points($sources);

        $numbers = array_values(array_filter(array_column($points, 'number')));
        self::assertNotEmpty($numbers, 'a dense grid cannot label every dot');
        self::assertLessThan(count($sources), count($numbers), 'the grid edge still has room for labels');
        self::assertSame(range(1, count($numbers)), $numbers, 'numbers are sequential in layout order');

        // Labels that were placed overlap neither each other nor a dot.
        $boxes = [];
        foreach ($points as $p) {
            if ($p['number'] !== null) {
                continue;
            }
            $w = mb_strlen($p['source']->name) * 6.2;
            $x0 = match ($p['anchor']) { 'start' => $p['lx'], 'end' => $p['lx'] - $w, default => $p['lx'] - $w / 2 };
            $box = [$x0, $p['ly'] - 10.0, $x0 + $w, $p['ly'] + 2.0];
            foreach ($boxes as $other) {
                self::assertFalse(
                    $box[0] < $other[2] && $other[0] < $box[2] && $box[1] < $other[3] && $other[1] < $box[3],
                    'two placed labels overlap',
                );
            }
            $boxes[] = $box;
        }
    }
}
