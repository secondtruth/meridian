<?php

declare(strict_types=1);

namespace Meridian\Spectrum;

use Meridian\Registry\Source;

/**
 * A reader's coverage over a period. Diagnostic by design: it counts no
 * streaks and rewards no volume — reading more is not the goal, reading
 * wider is.
 */
final readonly class BalanceReport
{
    /**
     * @param list<array{id: string, source: ?Source, count: int}> $sources most-read first
     * @param array<int, array<int, int>>                          $axisGrid      reads on the economic × cultural plane
     * @param array<int, array<int, int>>                          $axisGridOffer the dataset on the same plane
     */
    public function __construct(
        public int $days,
        public int $total,
        public Distribution $perspectives,
        public Distribution $economic,
        public Distribution $cultural,
        public Distribution $topics,
        public array $sources,
        public array $axisGrid,
        public array $axisGridOffer,
        public ?float $averageReliability,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }

    /** @return list<Distribution> the four dimensions, in display order */
    public function distributions(): array
    {
        return [$this->perspectives, $this->economic, $this->cultural, $this->topics];
    }

    /**
     * The gaps worth naming, as (axis, slice) pairs — what the reader
     * has not touched at all in the period.
     *
     * @return list<array{axis: string, slice: Slice}>
     */
    public function blindspots(): array
    {
        $blindspots = [];
        foreach ($this->distributions() as $distribution) {
            foreach ($distribution->blindspots() as $slice) {
                $blindspots[] = ['axis' => $distribution->axis, 'slice' => $slice];
            }
        }

        return $blindspots;
    }

    /** @return list<array{id: string, source: ?Source, count: int}> */
    public function topSources(int $limit = 5): array
    {
        return array_slice($this->sources, 0, $limit);
    }
}
