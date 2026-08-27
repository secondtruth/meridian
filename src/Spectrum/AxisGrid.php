<?php

declare(strict_types=1);

namespace Meridian\Spectrum;

use Meridian\Registry\Source;

/**
 * Counts sources on the economic × cultural band plane — the 5×5 grid
 * that shows the *shape* of coverage where two separate bars cannot
 * (only the diagonal occupied, a missing quadrant). Like every spectrum
 * view it is a mirror, never a score.
 */
final class AxisGrid
{
    /**
     * @param iterable<Source> $sources counted once each; pass a source
     *                                  repeatedly to weight it
     *
     * @return array<int, array<int, int>> [economic band][cultural band] => count
     */
    public static function count(iterable $sources): array
    {
        $grid = array_fill(0, 5, array_fill(0, 5, 0));
        foreach ($sources as $source) {
            ++$grid[Band::of($source->rating->economic)->value][Band::of($source->rating->cultural)->value];
        }

        return $grid;
    }

    /** @param array<int, array<int, int>> $grid */
    public static function isEmpty(array $grid): bool
    {
        foreach ($grid as $column) {
            if (array_sum($column) > 0) {
                return false;
            }
        }

        return true;
    }
}
