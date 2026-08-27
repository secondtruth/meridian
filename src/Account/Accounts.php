<?php

declare(strict_types=1);

namespace Meridian\Account;

/** Reader records and their preferences. */
final class Accounts
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Records the reader behind a verified ID token. Identity is the
     * (issuer, subject) pair — mail addresses change and are never used
     * as the key.
     */
    public function upsert(
        string $issuer,
        string $subject,
        ?string $email,
        ?string $displayName,
        \DateTimeImmutable $now,
    ): User {
        $stamp = Database::stamp($now);
        $this->db->pdo()->prepare(
            'INSERT INTO users (issuer, subject, email, display_name, created_at, last_seen_at)
                  VALUES (:issuer, :subject, :email, :name, :now, :now)
             ON CONFLICT (issuer, subject) DO UPDATE
                     SET email = excluded.email,
                         display_name = excluded.display_name,
                         last_seen_at = excluded.last_seen_at',
        )->execute([
            'issuer' => $issuer,
            'subject' => $subject,
            'email' => $email,
            'name' => $displayName,
            'now' => $stamp,
        ]);

        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM users WHERE issuer = :issuer AND subject = :subject',
        );
        $statement->execute(['issuer' => $issuer, 'subject' => $subject]);
        $row = $statement->fetch();
        if ($row === false) {
            throw new \RuntimeException('account could not be stored');
        }

        return User::fromRow($row);
    }

    public function find(int $id): ?User
    {
        $statement = $this->db->pdo()->prepare('SELECT * FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : User::fromRow($row);
    }

    public function preferences(int $userId): Preferences
    {
        $statement = $this->db->pdo()->prepare('SELECT * FROM preferences WHERE user_id = :id');
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();

        return $row === false ? new Preferences() : Preferences::fromRow($row);
    }

    public function savePreferences(int $userId, Preferences $preferences): void
    {
        $row = $preferences->toRow();
        $this->db->pdo()->prepare(
            'INSERT INTO preferences (user_id, locale, mode, muted_topics, daily_limit, track_reading, retention_days)
                  VALUES (:user_id, :locale, :mode, :muted_topics, :daily_limit, :track_reading, :retention_days)
             ON CONFLICT (user_id) DO UPDATE
                     SET locale = excluded.locale,
                         mode = excluded.mode,
                         muted_topics = excluded.muted_topics,
                         daily_limit = excluded.daily_limit,
                         track_reading = excluded.track_reading,
                         retention_days = excluded.retention_days',
        )->execute(['user_id' => $userId] + $row);
    }

    /** Erases the account and everything attached to it; reports stay, anonymised. */
    public function delete(int $userId): void
    {
        $this->db->pdo()->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
    }

    /**
     * Everything Meridian holds about this reader, for the data export.
     *
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        $rows = function (string $sql) use ($user): array {
            $statement = $this->db->pdo()->prepare($sql);
            $statement->execute(['id' => $user->id]);

            return $statement->fetchAll();
        };

        return [
            'exported_at' => Database::stamp(new \DateTimeImmutable()),
            'account' => [
                'issuer' => $user->issuer,
                'subject' => $user->subject,
                'email' => $user->email,
                'display_name' => $user->displayName,
                'created_at' => Database::stamp($user->createdAt),
                'last_seen_at' => Database::stamp($user->lastSeenAt),
            ],
            'preferences' => $this->preferences($user->id)->toRow(),
            'reads' => $rows('SELECT url, title, source_id, topic, read_at FROM reads WHERE user_id = :id ORDER BY read_at'),
            'watchlist' => $rows('SELECT url, title, source_id, topic, added_at FROM watchlist WHERE user_id = :id ORDER BY added_at'),
            'reports' => $rows('SELECT kind, url, title, source_id, topic, note, created_at, resolved_at FROM reports WHERE user_id = :id ORDER BY created_at'),
        ];
    }
}
