<?php

declare(strict_types=1);

namespace Meridian\Account;

use Meridian\Edition\Article;

/**
 * "Read later" — deliberately a small, finite pile, not a queue that
 * grows forever. Once the cap is reached the list must be cleared
 * before anything new goes in; a backlog you can never finish is the
 * same trap as an endless feed.
 */
final class Watchlist
{
    public const MAX_ENTRIES = 30;

    public function __construct(private readonly Database $db)
    {
    }

    /** @return bool false when the list is full */
    public function add(int $userId, Article $article, \DateTimeImmutable $now): bool
    {
        if ($this->contains($userId, $article->item->link)) {
            return true;
        }
        if ($this->count($userId) >= self::MAX_ENTRIES) {
            return false;
        }

        $this->db->pdo()->prepare(
            'INSERT INTO watchlist (user_id, url_hash, url, title, source_id, topic, added_at)
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

        return true;
    }

    public function remove(int $userId, string $url): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM watchlist WHERE user_id = :id AND url_hash = :hash',
        )->execute(['id' => $userId, 'hash' => ArticleRef::hash($url)]);
    }

    public function contains(int $userId, string $url): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT 1 FROM watchlist WHERE user_id = :id AND url_hash = :hash',
        );
        $statement->execute(['id' => $userId, 'hash' => ArticleRef::hash($url)]);

        return $statement->fetch() !== false;
    }

    /**
     * Which of the given links are already saved.
     *
     * @param list<string> $urls
     *
     * @return array<string, true> keyed by URL
     */
    public function savedAmong(int $userId, array $urls): array
    {
        if ($urls === []) {
            return [];
        }

        $hashes = array_map(ArticleRef::hash(...), $urls);
        $placeholders = implode(',', array_fill(0, count($hashes), '?'));
        $statement = $this->db->pdo()->prepare(
            "SELECT url FROM watchlist WHERE user_id = ? AND url_hash IN ({$placeholders})",
        );
        $statement->execute([$userId, ...$hashes]);

        return array_fill_keys(array_column($statement->fetchAll(), 'url'), true);
    }

    /** @return list<ArticleRef> oldest first — what has waited longest comes first */
    public function all(int $userId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM watchlist WHERE user_id = :id ORDER BY added_at',
        );
        $statement->execute(['id' => $userId]);

        return array_map(
            static fn (array $row): ArticleRef => ArticleRef::fromRow($row, 'added_at'),
            $statement->fetchAll(),
        );
    }

    public function count(int $userId): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) AS total FROM watchlist WHERE user_id = :id',
        );
        $statement->execute(['id' => $userId]);

        return (int) $statement->fetch()['total'];
    }

    public function clear(int $userId): int
    {
        $statement = $this->db->pdo()->prepare('DELETE FROM watchlist WHERE user_id = :id');
        $statement->execute(['id' => $userId]);

        return $statement->rowCount();
    }
}
