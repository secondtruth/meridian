<?php

declare(strict_types=1);

namespace Meridian\Auth;

/** The verified claims Meridian keeps from an ID token. */
final readonly class IdToken
{
    public function __construct(
        public string $issuer,
        public string $subject,
        public ?string $email,
        public ?string $name,
        public ?string $nonce,
    ) {
    }

    public static function fromClaims(object $claims): self
    {
        $string = static fn (string $key): ?string
            => isset($claims->{$key}) && is_string($claims->{$key}) && $claims->{$key} !== ''
                ? $claims->{$key}
                : null;

        return new self(
            issuer: $string('iss') ?? '',
            subject: $string('sub') ?? '',
            email: $string('email'),
            name: $string('name') ?? $string('preferred_username'),
            nonce: $string('nonce'),
        );
    }
}
