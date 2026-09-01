<?php

declare(strict_types=1);

namespace Meridian\Web;

use Meridian\Registry\Source;

/**
 * Lays out the /sources spectrum map (economic → x, cultural → y, TAN up)
 * in the 720 × 460 SVG viewBox: sources with identical ratings are spread
 * on a small ring so no dot hides another, and a label is placed only
 * where it overlaps neither a dot nor an earlier label — every other
 * source gets a number inside its dot and a line in the map index.
 *
 * @phpstan-type Point array{source: Source, cx: float, cy: float, r: float,
 *                          lx: float, ly: float, anchor: string, number: ?int}
 */
final class SpectrumMap
{
    private const PLOT_LEFT = 50.0;
    private const PLOT_TOP = 30.0;
    private const PLOT_WIDTH = 640.0;
    private const PLOT_HEIGHT = 380.0;
    private const LABEL_HEIGHT = 12.0;
    private const CHAR_WIDTH = 6.2;
    private const LABEL_GAP = 4.0;
    private const COINCIDENCE = 6.0;

    /**
     * @param iterable<Source> $sources
     * @return list<Point>
     */
    public static function points(iterable $sources): array
    {
        $points = [];
        foreach ($sources as $source) {
            $points[] = [
                'source' => $source,
                'cx' => self::PLOT_LEFT + ($source->rating->economic + 3.0) / 6.0 * self::PLOT_WIDTH,
                'cy' => self::PLOT_TOP + (3.0 - $source->rating->cultural) / 6.0 * self::PLOT_HEIGHT,
                'r' => 4.0 + $source->rating->reliability,
                'lx' => 0.0,
                'ly' => 0.0,
                'anchor' => 'start',
                'number' => null,
            ];
        }

        $points = self::spreadCoincident($points);
        usort($points, fn (array $a, array $b) => [$a['cy'], $a['cx']] <=> [$b['cy'], $b['cx']]);

        $placed = [];
        $next = 1;
        foreach ($points as $i => $point) {
            $candidate = self::placeLabel($point, $points, $i, $placed);
            if ($candidate === null) {
                $points[$i]['number'] = $next++;
                continue;
            }
            [$points[$i]['lx'], $points[$i]['ly'], $points[$i]['anchor'], $box] = $candidate;
            $placed[] = $box;
        }

        return $points;
    }

    /**
     * @param list<Point> $points
     * @return list<Point>
     */
    private static function spreadCoincident(array $points): array
    {
        $groups = [];
        foreach ($points as $i => $point) {
            $key = round($point['cx'] / self::COINCIDENCE) . ':' . round($point['cy'] / self::COINCIDENCE);
            $groups[$key][] = $i;
        }

        foreach ($groups as $members) {
            $n = count($members);
            if ($n < 2) {
                continue;
            }
            $rmax = max(array_map(fn (int $i) => $points[$i]['r'], $members));
            $ring = max($rmax + 3.0, $n * ($rmax * 2.0 + 2.0) / (2.0 * M_PI));
            $cx = array_sum(array_map(fn (int $i) => $points[$i]['cx'], $members)) / $n;
            $cy = array_sum(array_map(fn (int $i) => $points[$i]['cy'], $members)) / $n;
            foreach ($members as $k => $i) {
                $angle = -M_PI / 2.0 + 2.0 * M_PI * $k / $n;
                $points[$i]['cx'] = $cx + cos($angle) * $ring;
                $points[$i]['cy'] = $cy + sin($angle) * $ring;
            }
        }

        return $points;
    }

    /**
     * Tries right, left, below and above of the dot; returns the first
     * position whose label box is free, or null when none is.
     *
     * @param Point $point
     * @param list<Point> $points
     * @param list<array{float, float, float, float}> $placed
     * @return array{float, float, string, array{float, float, float, float}}|null
     */
    private static function placeLabel(array $point, array $points, int $self, array $placed): ?array
    {
        $width = mb_strlen($point['source']->name) * self::CHAR_WIDTH;
        $r = $point['r'] + self::LABEL_GAP;
        $candidates = [
            [$point['cx'] + $r, $point['cy'] + 4.0, 'start'],
            [$point['cx'] - $r, $point['cy'] + 4.0, 'end'],
            [$point['cx'], $point['cy'] + $r + 9.0, 'middle'],
            [$point['cx'], $point['cy'] - $r + 1.0, 'middle'],
        ];

        foreach ($candidates as [$lx, $ly, $anchor]) {
            $x0 = match ($anchor) {
                'start' => $lx,
                'end' => $lx - $width,
                default => $lx - $width / 2.0,
            };
            $box = [$x0, $ly - self::LABEL_HEIGHT + 2.0, $x0 + $width, $ly + 2.0];
            if ($box[0] < 0.0 || $box[2] > 720.0 || $box[1] < 20.0 || $box[3] > 420.0) {
                continue;
            }
            if (self::hitsDot($box, $points, $self) || self::hitsLabel($box, $placed)) {
                continue;
            }

            return [$lx, $ly, $anchor, $box];
        }

        return null;
    }

    /**
     * @param array{float, float, float, float} $box
     * @param list<Point> $points
     */
    private static function hitsDot(array $box, array $points, int $self): bool
    {
        foreach ($points as $i => $other) {
            if ($i === $self) {
                continue;
            }
            $pad = $other['r'] + 1.0;
            $dot = [$other['cx'] - $pad, $other['cy'] - $pad, $other['cx'] + $pad, $other['cy'] + $pad];
            if (self::intersects($box, $dot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{float, float, float, float} $box
     * @param list<array{float, float, float, float}> $placed
     */
    private static function hitsLabel(array $box, array $placed): bool
    {
        foreach ($placed as $other) {
            if (self::intersects($box, $other)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{float, float, float, float} $a
     * @param array{float, float, float, float} $b
     */
    private static function intersects(array $a, array $b): bool
    {
        return $a[0] < $b[2] && $b[0] < $a[2] && $a[1] < $b[3] && $b[1] < $a[3];
    }
}
