<?php

declare(strict_types=1);

namespace Meridian\Spectrum;

/** A reading distribution across one dimension, in canonical order. */
final readonly class Distribution
{
    /** @param list<Slice> $slices */
    public function __construct(
        public string $axis,
        public array $slices,
    ) {
    }

    /**
     * Builds the distribution from per-key counts. Keys not present in
     * the counts still appear — a bucket with zero reads is the whole
     * point of this page.
     *
     * @param list<string>           $keys  canonical order
     * @param array<string, int>     $read
     * @param array<string, int>|null $offer
     */
    public static function build(string $axis, array $keys, array $read, ?array $offer = null): self
    {
        $readTotal = array_sum($read);
        $offerTotal = $offer === null ? 0 : array_sum($offer);

        $slices = [];
        foreach ($keys as $key) {
            $count = $read[$key] ?? 0;
            $slices[] = new Slice(
                key: $key,
                read: $count,
                readShare: $readTotal > 0 ? $count / $readTotal * 100.0 : 0.0,
                offerShare: $offer !== null && $offerTotal > 0
                    ? ($offer[$key] ?? 0) / $offerTotal * 100.0
                    : null,
            );
        }

        return new self($axis, $slices);
    }

    /** @return list<Slice> */
    public function blindspots(): array
    {
        return array_values(array_filter($this->slices, static fn (Slice $s): bool => $s->isBlindspot()));
    }

    /** The bucket the reader leans on most relative to what is on offer. */
    public function mostOverweight(): ?Slice
    {
        $ranked = array_filter($this->slices, static fn (Slice $s): bool => $s->isOverweight());
        usort($ranked, static fn (Slice $a, Slice $b): int => ($b->gap() ?? 0.0) <=> ($a->gap() ?? 0.0));

        return $ranked[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return array_sum(array_map(static fn (Slice $s): int => $s->read, $this->slices)) === 0;
    }
}
