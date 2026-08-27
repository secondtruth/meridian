<?php

declare(strict_types=1);

namespace Meridian\Account;

/**
 * A stored pointer to an article — what the reading log and the
 * watchlist keep once the item has left the 48-hour window.
 */
final readonly class ArticleRef
{
    public function __construct(
        public string $url,
        public string $title,
        public string $sourceId,
        public string $topic,
        public \DateTimeImmutable $at,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row, string $timeColumn): self
    {
        return new self(
            url: (string) $row['url'],
            title: (string) $row['title'],
            sourceId: (string) $row['source_id'],
            topic: (string) $row['topic'],
            at: Database::parse((string) $row[$timeColumn]),
        );
    }

    /** Articles are identified by the hash of their link, not by the link itself. */
    public static function hash(string $url): string
    {
        return hash('sha256', $url);
    }
}
