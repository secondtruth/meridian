<?php

declare(strict_types=1);

namespace Meridian\Web;

/** A cookie to be set on the response. */
final readonly class Cookie
{
    public function __construct(
        public string $name,
        public string $value,
        public int $expires,
        public bool $httpOnly = true,
        public bool $secure = false,
        public string $sameSite = 'Lax',
    ) {
    }

    public static function forget(string $name): self
    {
        return new self($name, '', time() - 3600);
    }
}
