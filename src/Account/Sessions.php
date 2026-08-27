<?php

declare(strict_types=1);

namespace Meridian\Account;

use Meridian\Support\Random;

/**
 * Server-side sessions. The cookie carries a random token; only its
 * SHA-256 hash is stored, so a leaked database cannot be replayed as a
 * login. Each session carries its own CSRF token.
 */
final class Sessions
{
    public const COOKIE = 'meridian-session';
    public const LIFETIME_DAYS = 30;

    public function __construct(private readonly Database $db)
    {
    }

    /** @return string the raw token for the cookie — never stored as-is */
    public function start(int $userId, \DateTimeImmutable $now): string
    {
        $token = Random::token();
        $this->db->pdo()->prepare(
            'INSERT INTO sessions (token_hash, user_id, csrf_token, created_at, expires_at)
                  VALUES (:hash, :user_id, :csrf, :created, :expires)',
        )->execute([
            'hash' => self::hash($token),
            'user_id' => $userId,
            'csrf' => Random::token(),
            'created' => Database::stamp($now),
            'expires' => Database::stamp($now->modify('+' . self::LIFETIME_DAYS . ' days')),
        ]);

        return $token;
    }

    public function lookup(string $token, \DateTimeImmutable $now): ?Session
    {
        if (!$this->db->exists() || $token === '') {
            return null;
        }

        $statement = $this->db->pdo()->prepare(
            'SELECT user_id, csrf_token, expires_at FROM sessions
              WHERE token_hash = :hash AND expires_at > :now',
        );
        $statement->execute(['hash' => self::hash($token), 'now' => Database::stamp($now)]);
        $row = $statement->fetch();

        return $row === false ? null : new Session(
            userId: (int) $row['user_id'],
            csrfToken: (string) $row['csrf_token'],
            expiresAt: Database::parse((string) $row['expires_at']),
        );
    }

    public function destroy(string $token): void
    {
        if (!$this->db->exists()) {
            return;
        }

        $this->db->pdo()
            ->prepare('DELETE FROM sessions WHERE token_hash = :hash')
            ->execute(['hash' => self::hash($token)]);
    }

    /** @return int number of removed sessions */
    public function purgeExpired(\DateTimeImmutable $now): int
    {
        $statement = $this->db->pdo()->prepare('DELETE FROM sessions WHERE expires_at <= :now');
        $statement->execute(['now' => Database::stamp($now)]);

        return $statement->rowCount();
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
