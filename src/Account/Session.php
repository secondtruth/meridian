<?php

declare(strict_types=1);

namespace Meridian\Account;

/** A live session as stored on the server. */
final readonly class Session
{
    public function __construct(
        public int $userId,
        public string $csrfToken,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    public function verifyCsrf(?string $submitted): bool
    {
        return $submitted !== null && hash_equals($this->csrfToken, $submitted);
    }
}
