<?php

declare(strict_types=1);

namespace Meridian\Account;

/**
 * A reader identified by an OpenID Connect provider. Meridian stores no
 * password and no profile beyond what the ID token carries.
 */
final readonly class User
{
    public function __construct(
        public int $id,
        public string $issuer,
        public string $subject,
        public ?string $email,
        public ?string $displayName,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $lastSeenAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            issuer: (string) $row['issuer'],
            subject: (string) $row['subject'],
            email: $row['email'] !== null ? (string) $row['email'] : null,
            displayName: $row['display_name'] !== null ? (string) $row['display_name'] : null,
            createdAt: Database::parse((string) $row['created_at']),
            lastSeenAt: Database::parse((string) $row['last_seen_at']),
        );
    }

    /** Short name for the topbar: display name, else the local part of the mail address. */
    public function shortName(): string
    {
        if ($this->displayName !== null && trim($this->displayName) !== '') {
            return trim($this->displayName);
        }
        if ($this->email !== null && str_contains($this->email, '@')) {
            return substr($this->email, 0, (int) strpos($this->email, '@'));
        }

        return $this->subject;
    }

    public function initial(): string
    {
        return mb_strtoupper(mb_substr($this->shortName(), 0, 1));
    }
}
