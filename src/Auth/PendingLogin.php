<?php

declare(strict_types=1);

namespace Meridian\Auth;

use Meridian\Support\Random;

/**
 * The client half of an authorization-code handshake: CSRF protection
 * (state), replay protection (nonce) and PKCE (code verifier). Kept
 * server-side so none of it can be tampered with in transit.
 */
final readonly class PendingLogin
{
    public function __construct(
        public string $state,
        public string $nonce,
        public string $codeVerifier,
        public string $returnTo,
    ) {
    }

    public static function create(string $returnTo = '/'): self
    {
        return new self(
            state: Random::token(),
            nonce: Random::token(),
            codeVerifier: Random::token(64),
            returnTo: self::safeReturnTo($returnTo),
        );
    }

    /** PKCE S256 challenge derived from the verifier. */
    public function codeChallenge(): string
    {
        return Random::base64Url(hash('sha256', $this->codeVerifier, true));
    }

    /**
     * Only same-site paths may be returned to after login — an absolute
     * URL or a protocol-relative "//evil.example" would turn the login
     * into an open redirect.
     */
    public static function safeReturnTo(string $target): string
    {
        if ($target === '' || $target[0] !== '/' || str_starts_with($target, '//')) {
            return '/';
        }

        return $target;
    }
}
