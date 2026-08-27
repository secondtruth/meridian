<?php

declare(strict_types=1);

namespace Meridian\Support;

/** Unguessable, URL-safe tokens for sessions, CSRF and the OIDC handshake. */
final class Random
{
    public static function token(int $bytes = 32): string
    {
        return self::base64Url(random_bytes($bytes));
    }

    public static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
