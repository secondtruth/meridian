<?php

declare(strict_types=1);

namespace Meridian\Auth;

use Meridian\Account\Database;

/**
 * Stores in-flight logins. Entries are single-use: taking one deletes
 * it, so an intercepted callback URL cannot be replayed.
 */
final class PendingLogins
{
    public const LIFETIME_MINUTES = 15;

    public function __construct(private readonly Database $db)
    {
    }

    public function remember(PendingLogin $login, \DateTimeImmutable $now): void
    {
        $this->purgeExpired($now);
        $this->db->pdo()->prepare(
            'INSERT INTO auth_flows (state, nonce, code_verifier, return_to, created_at)
                  VALUES (:state, :nonce, :verifier, :return_to, :created)',
        )->execute([
            'state' => $login->state,
            'nonce' => $login->nonce,
            'verifier' => $login->codeVerifier,
            'return_to' => $login->returnTo,
            'created' => Database::stamp($now),
        ]);
    }

    public function take(string $state, \DateTimeImmutable $now): ?PendingLogin
    {
        if (!$this->db->exists() || $state === '') {
            return null;
        }

        $oldest = Database::stamp($now->modify('-' . self::LIFETIME_MINUTES . ' minutes'));
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM auth_flows WHERE state = :state AND created_at > :oldest',
        );
        $statement->execute(['state' => $state, 'oldest' => $oldest]);
        $row = $statement->fetch();

        $this->db->pdo()->prepare('DELETE FROM auth_flows WHERE state = :state')
            ->execute(['state' => $state]);

        return $row === false ? null : new PendingLogin(
            state: (string) $row['state'],
            nonce: (string) $row['nonce'],
            codeVerifier: (string) $row['code_verifier'],
            returnTo: PendingLogin::safeReturnTo((string) $row['return_to']),
        );
    }

    public function purgeExpired(\DateTimeImmutable $now): int
    {
        $statement = $this->db->pdo()->prepare('DELETE FROM auth_flows WHERE created_at <= :oldest');
        $statement->execute([
            'oldest' => Database::stamp($now->modify('-' . self::LIFETIME_MINUTES . ' minutes')),
        ]);

        return $statement->rowCount();
    }
}
