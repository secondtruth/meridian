<?php

declare(strict_types=1);

namespace Meridian\Registry;

/**
 * A media house / publisher and the outlets it publishes. Groups the
 * flat source dataset by its `publisher` field so the product can show
 * ownership at a glance — who is behind the news, and where a single
 * house speaks through more than one outlet.
 */
final readonly class Publisher
{
    /** @param non-empty-list<Source> $outlets */
    public function __construct(
        public string $name,
        public array $outlets,
    ) {
    }

    public function outletCount(): int
    {
        return count($this->outlets);
    }

    /** True when one house publishes several of the listed outlets. */
    public function isGroup(): bool
    {
        return $this->outletCount() > 1;
    }

    /** @return list<string> distinct ownership categories, ordered as encountered */
    public function ownerships(): array
    {
        return $this->distinct(fn (Source $s): string => $s->ownership);
    }

    /** @return list<string> distinct funding sources across all outlets */
    public function fundings(): array
    {
        $seen = [];
        foreach ($this->outlets as $outlet) {
            foreach ($outlet->funding as $funding) {
                $seen[$funding] = true;
            }
        }

        return array_keys($seen);
    }

    /** @return list<string> distinct two-letter country codes */
    public function countries(): array
    {
        return $this->distinct(fn (Source $s): string => $s->country);
    }

    /** @return list<string> distinct perspectives */
    public function perspectives(): array
    {
        return $this->distinct(fn (Source $s): string => $s->perspective);
    }

    /** Reliability-weighted-free mean of the economic axis across outlets. */
    public function averageEconomic(): float
    {
        return $this->mean(fn (Source $s): float => $s->rating->economic);
    }

    public function averageCultural(): float
    {
        return $this->mean(fn (Source $s): float => $s->rating->cultural);
    }

    public function minReliability(): int
    {
        return (int) min(array_map(fn (Source $s): int => $s->rating->reliability, $this->outlets));
    }

    public function maxReliability(): int
    {
        return (int) max(array_map(fn (Source $s): int => $s->rating->reliability, $this->outlets));
    }

    /**
     * @param callable(Source): string $of
     * @return list<string>
     */
    private function distinct(callable $of): array
    {
        $seen = [];
        foreach ($this->outlets as $outlet) {
            $seen[$of($outlet)] = true;
        }

        return array_keys($seen);
    }

    /** @param callable(Source): float $of */
    private function mean(callable $of): float
    {
        $sum = 0.0;
        foreach ($this->outlets as $outlet) {
            $sum += $of($outlet);
        }

        return $sum / $this->outletCount();
    }
}
