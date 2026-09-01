<?php

declare(strict_types=1);

namespace Meridian\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Meridian\Support\Http;

/**
 * OpenID Connect relying party: authorization-code flow with PKCE.
 *
 * Provider metadata and signing keys are discovered and cached on disk.
 * Every ID token is checked for signature, issuer, audience and nonce —
 * a token that fails any of these is not a login.
 */
final class OidcClient
{
    private const METADATA_TTL_SECONDS = 86400;
    private const JWKS_TTL_SECONDS = 21600;
    private const CLOCK_SKEW_SECONDS = 60;

    public function __construct(
        private readonly OidcConfig $config,
        private readonly string $cacheDir,
        private readonly Http $http = new Http(),
    ) {
    }

    /** Where the provider sends the reader back to: the configured URI, or this deployment's origin. */
    public function redirectUri(string $origin): string
    {
        return $this->config->redirectUri($origin);
    }

    /** Where to send the reader to authenticate. */
    public function authorizationUrl(PendingLogin $login, string $redirectUri): string
    {
        $endpoint = $this->endpoint('authorization_endpoint');
        $parameters = [
            'response_type' => 'code',
            'client_id' => $this->config->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $this->config->scopes),
            'state' => $login->state,
            'nonce' => $login->nonce,
            'code_challenge' => $login->codeChallenge(),
            'code_challenge_method' => 'S256',
        ];

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($parameters);
    }

    /**
     * Trades the callback code for a verified identity. Throws when the
     * provider answers with anything Meridian cannot fully verify.
     */
    public function exchangeCode(string $code, PendingLogin $login, string $redirectUri): IdToken
    {
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->config->clientId,
            'code_verifier' => $login->codeVerifier,
        ];

        $basicAuth = null;
        if ($this->config->clientSecret !== '') {
            if ($this->supportsBasicAuth()) {
                $basicAuth = [$this->config->clientId, $this->config->clientSecret];
            } else {
                $form['client_secret'] = $this->config->clientSecret;
            }
        }

        $response = $this->http->postForm($this->endpoint('token_endpoint'), $form, $basicAuth);
        $idToken = $response['id_token'] ?? null;
        if (!is_string($idToken) || $idToken === '') {
            throw new \RuntimeException('token response carried no id_token');
        }

        return $this->verifyIdToken($idToken, $login->nonce);
    }

    /** Verifies signature and claims; the nonce ties the token to this handshake. */
    public function verifyIdToken(string $jwt, string $expectedNonce): IdToken
    {
        JWT::$leeway = self::CLOCK_SKEW_SECONDS;

        try {
            $claims = JWT::decode($jwt, JWK::parseKeySet($this->jwks(false), 'RS256'));
        } catch (\UnexpectedValueException | \DomainException) {
            // Signing keys rotate; retry once against a fresh key set.
            $claims = JWT::decode($jwt, JWK::parseKeySet($this->jwks(true), 'RS256'));
        }

        $token = IdToken::fromClaims($claims);
        $issuer = (string) ($this->metadata()['issuer'] ?? $this->config->issuer);

        if (!hash_equals($issuer, $token->issuer)) {
            throw new \RuntimeException('id_token was issued by a different provider');
        }
        if ($token->subject === '') {
            throw new \RuntimeException('id_token carries no subject');
        }
        if (!$this->audienceMatches($claims)) {
            throw new \RuntimeException('id_token was not issued for this client');
        }
        if ($token->nonce === null || !hash_equals($expectedNonce, $token->nonce)) {
            throw new \RuntimeException('id_token nonce does not match the login attempt');
        }

        return $token;
    }

    /** The provider's own logout page, when it publishes one. */
    public function endSessionUrl(): ?string
    {
        $endpoint = $this->metadata()['end_session_endpoint'] ?? null;

        return is_string($endpoint) && $endpoint !== '' ? $endpoint : null;
    }

    private function audienceMatches(object $claims): bool
    {
        $audiences = $claims->aud ?? null;
        $audiences = is_array($audiences) ? $audiences : [$audiences];
        foreach ($audiences as $audience) {
            if (is_string($audience) && hash_equals($this->config->clientId, $audience)) {
                return true;
            }
        }

        return false;
    }

    private function supportsBasicAuth(): bool
    {
        $methods = $this->metadata()['token_endpoint_auth_methods_supported'] ?? null;

        return !is_array($methods) || in_array('client_secret_basic', $methods, true);
    }

    private function endpoint(string $name): string
    {
        $endpoint = $this->metadata()[$name] ?? null;
        if (!is_string($endpoint) || $endpoint === '') {
            throw new \RuntimeException("provider metadata has no {$name}");
        }

        return $endpoint;
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        return $this->cached('metadata', self::METADATA_TTL_SECONDS, false, fn (): array
            => $this->http->getJson($this->config->discoveryUrl()));
    }

    /** @return array<string, mixed> */
    private function jwks(bool $refresh): array
    {
        return $this->cached('jwks', self::JWKS_TTL_SECONDS, $refresh, fn (): array
            => $this->http->getJson($this->endpoint('jwks_uri')));
    }

    /**
     * @param callable(): array<string, mixed> $fetch
     *
     * @return array<string, mixed>
     */
    private function cached(string $name, int $ttl, bool $refresh, callable $fetch): array
    {
        $file = sprintf('%s/oidc-%s-%s.json', $this->cacheDir, $name, substr(sha1($this->config->issuer), 0, 12));

        if (!$refresh && is_file($file) && time() - (int) filemtime($file) < $ttl) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $data = $fetch();
        if (is_dir($this->cacheDir) || mkdir($this->cacheDir, 0o775, true) || is_dir($this->cacheDir)) {
            file_put_contents($file, json_encode($data), LOCK_EX);
        }

        return $data;
    }
}
