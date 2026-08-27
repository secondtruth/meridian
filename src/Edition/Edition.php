<?php

declare(strict_types=1);

namespace Meridian\Edition;

/** One day's curated output in either reading mode. */
final class Edition
{
    /** @param list<Section> $sections */
    public function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly Mode $mode,
        public array $sections,
    ) {
    }

    public function total(): int
    {
        return array_sum(array_map(fn (Section $s) => count($s->articles), $this->sections));
    }

    /** @return list<string> distinct source IDs across all sections */
    public function sourceIds(): array
    {
        $ids = [];
        foreach ($this->sections as $section) {
            foreach ($section->articles as $article) {
                $ids[$article->source->id] = true;
            }
        }

        return array_keys($ids);
    }

    /** @return list<string> distinct perspectives across all sections */
    public function perspectives(): array
    {
        $perspectives = [];
        foreach ($this->sections as $section) {
            foreach ($section->articles as $article) {
                $perspectives[$article->source->perspective] = true;
            }
        }

        return array_keys($perspectives);
    }
}
