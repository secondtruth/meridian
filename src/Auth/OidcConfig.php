<?php

declare(strict_types=1);

namespace Meridian\Auth;

/**
 * Identity-provider settings, from environment variables or an optional
 * config/oidc.php returning the same keys. When nothing is configured
 * Meridian simply runs without accounts — the anonymous edition is the
 * product's baseline, not a fallback.
 */
final readonly class OidcConfig
{
    public const CALLBACK_PATH = '/auth/callback';

    /** @param list<string> $scopes */
    public function __construct(
        public string $issuer,
        public string $clientId,
        public string $clientSecret,
        public ?string $redirectUri = null,
        public array $scopes = ['openid', 'profile', 'email'],
    ) {
    }

    public static function load(string $rootDir): ?self
    {
        $file = $rootDir . '/config/oidc.php';
        $settings = is_file($file) ? (array) require $file : [];

        foreach ([
            'issuer' => 'MERIDIAN_OIDC_ISSUER',
            'client_id' => 'MERIDIAN_OIDC_CLIENT_ID',
            'client_secret' => 'MERIDIAN_OIDC_CLIENT_SECRET',
            'redirect_uri' => 'MERIDIAN_OIDC_REDIRECT_URI',
            'scopes' => 'MERIDIAN_OIDC_SCOPES',
        ] as $key => $variable) {
            $value = getenv($variable);
            if (is_string($value) && $value !== '') {
                $settings[$key] = $key === 'scopes' ? preg_split('/[\s,]+/', $value) : $value;
            }
        }

        $issuer = trim((string) ($settings['issuer'] ?? ''));
        $clientId = trim((string) ($settings['client_id'] ?? ''));
        if ($issuer === '' || $clientId === '') {
            return null;
        }

        $scopes = array_values(array_filter(array_map(strval(...), (array) ($settings['scopes'] ?? []))));
        $redirect = trim((string) ($settings['redirect_uri'] ?? ''));

        return new self(
            issuer: rtrim($issuer, '/'),
            clientId: $clientId,
            clientSecret: (string) ($settings['client_secret'] ?? ''),
            redirectUri: $redirect !== '' ? $redirect : null,
            scopes: $scopes !== [] ? array_values(array_unique(['openid', ...$scopes])) : ['openid', 'profile', 'email'],
        );
    }

    /** The registered callback, or the current origin's default. */
    public function redirectUri(string $origin): string
    {
        return $this->redirectUri ?? $origin . self::CALLBACK_PATH;
    }

    public function discoveryUrl(): string
    {
        return $this->issuer . '/.well-known/openid-configuration';
    }
}
