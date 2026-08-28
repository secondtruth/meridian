<?php

declare(strict_types=1);

namespace Meridian\Account;

use Meridian\Edition\Article;
use Meridian\Registry\Source;

/**
 * Reader objections to a classification. The dataset is meant to be
 * contradicted (METHODOLOGY.md) — this is the low-friction path for it.
 * Reports are reviewed from the command line; nothing a reader submits
 * changes the dataset by itself.
 *
 * A report anchors either to an article (a classification seen in the
 * edition) or to a source alone (its rating, contested from /sources);
 * source-anchored rows store empty url, title and topic
 * (docs/accounts.md §11).
 */
final class Reports
{
    public const KINDS = ['topic', 'rating', 'source', 'other'];
    public const SOURCE_KINDS = ['rating', 'source', 'other'];
    public const MAX_NOTE_LENGTH = 1000;

    public function __construct(private readonly Database $db)
    {
    }

    public function submit(int $userId, Article $article, string $kind, string $note, \DateTimeImmutable $now): void
    {
        $this->insert($userId, self::KINDS, $kind, $article->item->link, $article->item->title, $article->source->id, $article->topic, $note, $now);
    }

    public function submitForSource(int $userId, Source $source, string $kind, string $note, \DateTimeImmutable $now): void
    {
        $this->insert($userId, self::SOURCE_KINDS, $kind, '', '', $source->id, '', $note, $now);
    }

    /** @param list<string> $kinds */
    private function insert(int $userId, array $kinds, string $kind, string $url, string $title, string $sourceId, string $topic, string $note, \DateTimeImmutable $now): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO reports (user_id, kind, url, title, source_id, topic, note, created_at)
                  VALUES (:user_id, :kind, :url, :title, :source_id, :topic, :note, :now)',
        )->execute([
            'user_id' => $userId,
            'kind' => in_array($kind, $kinds, true) ? $kind : 'other',
            'url' => $url,
            'title' => $title,
            'source_id' => $sourceId,
            'topic' => $topic,
            'note' => mb_substr(trim($note), 0, self::MAX_NOTE_LENGTH),
            'now' => Database::stamp($now),
        ]);
    }

    /**
     * @return list<array<string, mixed>> unresolved reports, oldest first
     */
    public function open(): array
    {
        return $this->db->pdo()
            ->query('SELECT * FROM reports WHERE resolved_at IS NULL ORDER BY created_at')
            ->fetchAll();
    }

    public function resolve(int $id, \DateTimeImmutable $now): bool
    {
        $statement = $this->db->pdo()->prepare(
            'UPDATE reports SET resolved_at = :now WHERE id = :id AND resolved_at IS NULL',
        );
        $statement->execute(['id' => $id, 'now' => Database::stamp($now)]);

        return $statement->rowCount() > 0;
    }

    /** How many reports this reader already filed today — a simple abuse brake. */
    public function countToday(int $userId, \DateTimeImmutable $now): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) AS total FROM reports WHERE user_id = :id AND created_at >= :since',
        );
        $statement->execute([
            'id' => $userId,
            'since' => Database::stamp($now->modify('-1 day')),
        ]);

        return (int) $statement->fetch()['total'];
    }
}
