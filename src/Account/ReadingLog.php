<?php

declare(strict_types=1);

namespace Meridian\Account;

use Meridian\Edition\Article;

/**
 * What a reader has opened, so the edition can mark it on any device
 * and the balance page can hold up a mirror.
 *
 * Only articles Meridian itself classified can be recorded — the caller
 * resolves the link against the item cache first, so a forged request
 * cannot invent reading history.
 */
final class ReadingLog
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Recording the same article twice keeps the first timestamp. */
    public function record(int $userId, Article $article, \DateTimeImmutable $now): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO reads (user_id, url_hash, url, title, source_id, topic, read_at)
                  VALUES (:user_id, :hash, :url, :title, :source_id, :topic, :now)
             ON CONFLICT (user_id, url_hash) DO NOTHING',
        )->execute([
            'user_id' => $userId,
            'hash' => ArticleRef::hash($article->item->link),
            'url' => $article->item->link,
            'title' => $article->item->title,
            'source_id' => $article->source->id,
            'topic' => $article->topic,
            'now' => Database::stamp($now),
        ]);
    }

    /**
     * Which of the given links this reader has already opened.
     *
     * @param list<string> $urls
     *
     * @return array<string, true> keyed by URL
     */
    public function readAmong(int $userId, array $urls): array
    {
        if ($urls === []) {
            return [];
        }

        $hashes = array_map(ArticleRef::hash(...), $urls);
        $placeholders = implode(',', array_fill(0, count($hashes), '?'));
        $statement = $this->db->pdo()->prepare(
            "SELECT url FROM reads WHERE user_id = ? AND url_hash IN ({$placeholders})",
        );
        $statement->execute([$userId, ...$hashes]);

        return array_fill_keys(array_column($statement->fetchAll(), 'url'), true);
    }

    public function countSince(int $userId, \DateTimeImmutable $since): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) AS total FROM reads WHERE user_id = :id AND read_at >= :since',
        );
        $statement->execute(['id' => $userId, 'since' => Database::stamp($since)]);

        return (int) $statement->fetch()['total'];
    }

    /** @return list<ArticleRef> newest first */
    public function since(int $userId, \DateTimeImmutable $since): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM reads WHERE user_id = :id AND read_at >= :since ORDER BY read_at DESC',
        );
        $statement->execute(['id' => $userId, 'since' => Database::stamp($since)]);

        return array_map(
            static fn (array $row): ArticleRef => ArticleRef::fromRow($row, 'read_at'),
            $statement->fetchAll(),
        );
    }

    /** Applies the reader's retention setting; 0 days means "keep until deleted". */
    public function prune(int $userId, int $retentionDays, \DateTimeImmutable $now): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }

        $statement = $this->db->pdo()->prepare(
            'DELETE FROM reads WHERE user_id = :id AND read_at < :cutoff',
        );
        $statement->execute([
            'id' => $userId,
            'cutoff' => Database::stamp($now->modify("-{$retentionDays} days")),
        ]);

        return $statement->rowCount();
    }

    public function clear(int $userId): int
    {
        $statement = $this->db->pdo()->prepare('DELETE FROM reads WHERE user_id = :id');
        $statement->execute(['id' => $userId]);

        return $statement->rowCount();
    }
}
