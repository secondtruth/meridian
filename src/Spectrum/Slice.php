<?php

declare(strict_types=1);

namespace Meridian\Spectrum;

/**
 * One bucket of a reading distribution: how much of it the reader
 * actually read, next to how much of it the dataset offers.
 */
final readonly class Slice
{
    /** Percentage points by which a share must exceed the offer to be called out. */
    public const NOTABLE_GAP = 15.0;

    public function __construct(
        public string $key,
        public int $read,
        public float $readShare,
        public ?float $offerShare = null,
    ) {
    }

    /** Read share minus offered share, in percentage points; null without a reference. */
    public function gap(): ?float
    {
        return $this->offerShare === null ? null : $this->readShare - $this->offerShare;
    }

    /** Nothing read although the bucket exists — the thing worth naming. */
    public function isBlindspot(): bool
    {
        return $this->read === 0 && ($this->offerShare === null || $this->offerShare > 0.0);
    }

    public function isOverweight(): bool
    {
        return ($this->gap() ?? 0.0) >= self::NOTABLE_GAP;
    }
}
