<?php

declare(strict_types=1);

namespace Meridian\Account;

/**
 * The account store: a single SQLite file next to the item cache.
 *
 * Accounts are optional in Meridian — the anonymous daily edition stays
 * the primary way to read. The connection is therefore opened lazily, so
 * an installation without a configured identity provider never creates
 * the file at all.
 *
 * All timestamps are stored as UTC "Y-m-d H:i:s" strings, which sort
 * lexically and compare correctly in SQL.
 */
final class Database
{
    public const STAMP_FORMAT = 'Y-m-d H:i:s';

    private ?\PDO $pdo = null;

    public function __construct(private readonly string $path)
    {
    }

    /** In-memory database for tests. */
    public static function inMemory(): self
    {
        return new self(':memory:');
    }

    public function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        if ($this->path !== ':memory:') {
            $dir = dirname($this->path);
            if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
                throw new \RuntimeException("cannot create account store directory {$dir}");
            }
        }

        $this->pdo = new \PDO('sqlite:' . $this->path, options: [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->migrate($this->pdo);

        return $this->pdo;
    }

    /** True once an account store exists — no file, no accounts. */
    public function exists(): bool
    {
        return $this->path === ':memory:' || is_file($this->path);
    }

    public static function stamp(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format(self::STAMP_FORMAT);
    }

    public static function parse(string $stamp): \DateTimeImmutable
    {
        return new \DateTimeImmutable($stamp, new \DateTimeZone('UTC'));
    }

    private function migrate(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                issuer        TEXT NOT NULL,
                subject       TEXT NOT NULL,
                email         TEXT,
                display_name  TEXT,
                created_at    TEXT NOT NULL,
                last_seen_at  TEXT NOT NULL,
                UNIQUE (issuer, subject)
            );

            CREATE TABLE IF NOT EXISTS preferences (
                user_id        INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                locale         TEXT NOT NULL,
                mode           TEXT NOT NULL,
                muted_topics   TEXT NOT NULL DEFAULT '',
                daily_limit    INTEGER NOT NULL DEFAULT 1,
                track_reading  INTEGER NOT NULL DEFAULT 1,
                retention_days INTEGER NOT NULL DEFAULT 90
            );

            CREATE TABLE IF NOT EXISTS sessions (
                token_hash TEXT PRIMARY KEY,
                user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                csrf_token TEXT NOT NULL,
                created_at TEXT NOT NULL,
                expires_at TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS sessions_expiry ON sessions (expires_at);

            CREATE TABLE IF NOT EXISTS auth_flows (
                state         TEXT PRIMARY KEY,
                nonce         TEXT NOT NULL,
                code_verifier TEXT NOT NULL,
                return_to     TEXT NOT NULL,
                created_at    TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS reads (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                url_hash  TEXT NOT NULL,
                url       TEXT NOT NULL,
                title     TEXT NOT NULL,
                source_id TEXT NOT NULL,
                topic     TEXT NOT NULL,
                read_at   TEXT NOT NULL,
                UNIQUE (user_id, url_hash)
            );
            CREATE INDEX IF NOT EXISTS reads_by_user ON reads (user_id, read_at);

            CREATE TABLE IF NOT EXISTS watchlist (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                url_hash  TEXT NOT NULL,
                url       TEXT NOT NULL,
                title     TEXT NOT NULL,
                source_id TEXT NOT NULL,
                topic     TEXT NOT NULL,
                added_at  TEXT NOT NULL,
                UNIQUE (user_id, url_hash)
            );
            CREATE INDEX IF NOT EXISTS watchlist_by_user ON watchlist (user_id, added_at);

            CREATE TABLE IF NOT EXISTS reports (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
                kind        TEXT NOT NULL,
                url         TEXT NOT NULL,
                title       TEXT NOT NULL,
                source_id   TEXT NOT NULL,
                topic       TEXT NOT NULL,
                note        TEXT NOT NULL,
                created_at  TEXT NOT NULL,
                resolved_at TEXT
            );
            SQL);
    }
}
