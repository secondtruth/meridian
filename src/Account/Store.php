<?php

declare(strict_types=1);

namespace Meridian\Account;

/** Everything account-related behind one handle, sharing a single connection. */
final class Store
{
    public readonly Accounts $accounts;
    public readonly Sessions $sessions;
    public readonly ReadingLog $reads;
    public readonly Watchlist $watchlist;
    public readonly Reports $reports;

    public function __construct(public readonly Database $db)
    {
        $this->accounts = new Accounts($db);
        $this->sessions = new Sessions($db);
        $this->reads = new ReadingLog($db);
        $this->watchlist = new Watchlist($db);
        $this->reports = new Reports($db);
    }

    public static function at(string $rootDir): self
    {
        return new self(new Database($rootDir . '/data/accounts.sqlite'));
    }

    /** Deletes expired sessions and login attempts, and each reader's aged-out history. */
    public function prune(\DateTimeImmutable $now): int
    {
        if (!$this->db->exists()) {
            return 0;
        }

        $removed = $this->sessions->purgeExpired($now);
        $users = $this->db->pdo()->query('SELECT id FROM users')->fetchAll();
        foreach ($users as $row) {
            $userId = (int) $row['id'];
            $removed += $this->reads->prune($userId, $this->accounts->preferences($userId)->retentionDays, $now);
        }

        return $removed;
    }
}
