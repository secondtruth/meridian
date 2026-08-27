<?php

declare(strict_types=1);

namespace Meridian\Collection;

/**
 * One curated collection: an explicit source list with finite caps.
 * Collections are orthogonal to the focus topics — they group by
 * audience, genre or vantage point, never by engagement.
 */
final readonly class Collection
{
    /** @param list<string> $sources source IDs from the registry */
    public function __construct(
        public string $id,
        public array $sources,
        public int $windowDays,
        public int $cap,
        public int $perSource,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            sources: array_values(array_map(strval(...), $data['sources'] ?? [])),
            windowDays: (int) ($data['window_days'] ?? 7),
            cap: (int) ($data['cap'] ?? 12),
            perSource: (int) ($data['per_source'] ?? 4),
        );
    }
}
