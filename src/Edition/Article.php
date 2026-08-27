<?php

declare(strict_types=1);

namespace Meridian\Edition;

use Meridian\Feed\Item;
use Meridian\Registry\Source;

/**
 * One selected item joined with its source and topic. An article may
 * carry further tellings of the same story from other sources
 * (rating-system.md §5, story clustering); those members have no
 * members of their own.
 */
final readonly class Article
{
    /** @param list<Article> $alsoCoveredBy other sources' tellings, newest first */
    public function __construct(
        public Item $item,
        public Source $source,
        public string $topic,
        public array $alsoCoveredBy = [],
    ) {
    }

    /** @return list<Article> every telling of this story, this one first */
    public function tellings(): array
    {
        return [$this, ...$this->alsoCoveredBy];
    }
}
