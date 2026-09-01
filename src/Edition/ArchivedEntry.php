<?php

declare(strict_types=1);

namespace Meridian\Edition;

/**
 * One article of a frozen edition. `article` is null when its source has
 * since left the dataset — the stored text still renders, but without a
 * rating there is nothing to display it as a card with.
 */
final readonly class ArchivedEntry
{
    public function __construct(
        public string $sourceName,
        public string $title,
        public string $link,
        public string $summary,
        public ?Article $article,
    ) {
    }
}
