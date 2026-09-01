<?php

declare(strict_types=1);

namespace Meridian\Edition;

/** One topic of a frozen edition, restored for display. */
final readonly class ArchivedSection
{
    /** @param list<ArchivedEntry> $entries */
    public function __construct(
        public string $topic,
        public array $entries,
    ) {
    }
}
