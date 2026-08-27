<?php

declare(strict_types=1);

namespace Meridian\Tests;

use Firebase\JWT\JWT;
use Meridian\Account\Database;
use Meridian\Auth\OidcClient;
use Meridian\Auth\OidcConfig;
use Meridian\Auth\PendingLogin;
use Meridian\Auth\PendingLogins;
use Meridian\Support\Http;
use Meridian\Support\Random;
use PHPUnit\Framework\TestCase;

/** Canned identity-provider answers, so no test touches the network. */
final class FakeHttp extends Http
{
    /** @var list<string> */
    public array $requested = [];

    /** @param array<string, array<string, mixed>> $responses */
    public function __construct(private readonly array $responses)
    {
    }

    public function getJson(string $url): array
    {
        $this->requested[] = $url;

        return $this->responses[$url] ?? throw new \RuntimeException("unexpected request to {$url}");
    }

    public function postForm(string $url, array $form, ?array $basicAuth = null): array
    {
        $this->requested[] = $url;

        return $this->responses[$url] ?? throw new \RuntimeException("unexpected request to {$url}");
    }
}

final class AuthTest extends TestCase
{
    private const ISSUER = 'https://id.example';
    private const CLIENT_ID = 'meridian';

    private string $cacheDir;
    /** @var resource|\OpenSSLAsymmetricKey */
    private $key;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/meridian-auth-test-' . bin2hex(random_bytes(6));
        mkdir($this->cacheDir, 0o775, true);
        $this->key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->cacheDir);
    }

    // ── PKCE and handshake state ──────────────────────────────────────

    public function testCodeChallengeFollowsTheRfc7636TestVector(): void
    {
        $login = new PendingLogin(
            state: 'state',
            nonce: 'nonce',
            codeVerifier: 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk',
            returnTo: '/',
        );

        self::assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $login->codeChallenge());
    }

    public function testEveryHandshakeGetsFreshUnguessableValues(): void
    {
        $first = PendingLogin::create();
        $second = PendingLogin::create();

        self::assertNotSame($first->state, $second->state);
        self::assertNotSame($first->nonce, $second->nonce);
        self::assertNotSame($first->codeVerifier, $second->codeVerifier);
        self::assertGreaterThanOrEqual(43, strlen($first->codeVerifier));
    }

    /** The return target must never become an open redirect. */
    public function testOnlySameSitePathsSurviveAsReturnTarget(): void
    {
        self::assertSame('/balance', PendingLogin::safeReturnTo('/balance'));
        self::assertSame('/', PendingLogin::safeReturnTo('//evil.example/phish'));
        self::assertSame('/', PendingLogin::safeReturnTo('https://evil.example'));
        self::assertSame('/', PendingLogin::safeReturnTo('javascript:alert(1)'));
        self::assertSame('/', PendingLogin::safeReturnTo(''));
    }

    public function testAPendingLoginCanOnlyBeRedeemedOnce(): void
    {
        $store = new PendingLogins(Database::inMemory());
        $now = new \DateTimeImmutable('2026-08-05 12:00:00');
        $login = PendingLogin::create('/watchlist');
        $store->remember($login, $now);

        $taken = $store->take($login->state, $now);
        self::assertNotNull($taken);
        self::assertSame($login->codeVerifier, $taken->codeVerifier);
        self::assertSame('/watchlist', $taken->returnTo);

        self::assertNull($store->take($login->state, $now));
    }

    public function testAStaleLoginAttemptIsRejected(): void
    {
        $store = new PendingLogins(Database::inMemory());
        $now = new \DateTimeImmutable('2026-08-05 12:00:00');
        $login = PendingLogin::create();
        $store->remember($login, $now);

        self::assertNull($store->take($login->state, $now->modify('+20 minutes')));
    }

    // ── Authorization request ─────────────────────────────────────────

    public function testAuthorizationUrlCarriesPkceStateAndNonce(): void
    {
        $login = PendingLogin::create();
        $url = $this->client()->authorizationUrl($login, 'https://meridian.example/auth/callback');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertStringStartsWith(self::ISSUER . '/authorize', $url);
        self::assertSame('code', $query['response_type']);
        self::assertSame(self::CLIENT_ID, $query['client_id']);
        self::assertSame('https://meridian.example/auth/callback', $query['redirect_uri']);
        self::assertSame($login->state, $query['state']);
        self::assertSame($login->nonce, $query['nonce']);
        self::assertSame($login->codeChallenge(), $query['code_challenge']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertStringContainsString('openid', $query['scope']);
    }

    // ── ID token verification ─────────────────────────────────────────

    public function testAValidIdTokenYieldsTheIdentity(): void
    {
        $identity = $this->client()->verifyIdToken($this->idToken(['nonce' => 'the-nonce']), 'the-nonce');

        self::assertSame(self::ISSUER, $identity->issuer);
        self::assertSame('subject-1', $identity->subject);
        self::assertSame('ada@example.org', $identity->email);
        self::assertSame('Ada', $identity->name);
    }

    public function testAnAudienceListContainingThisClientIsAccepted(): void
    {
        $token = $this->idToken(['nonce' => 'n', 'aud' => ['other-client', self::CLIENT_ID]]);

        self::assertSame('subject-1', $this->client()->verifyIdToken($token, 'n')->subject);
    }

    public function testAReplayedTokenFromAnotherHandshakeIsRejected(): void
    {
        $this->expectExceptionMessage('nonce');

        $this->client()->verifyIdToken($this->idToken(['nonce' => 'a-different-nonce']), 'the-nonce');
    }

    public function testATokenWithoutNonceIsRejected(): void
    {
        $this->expectExceptionMessage('nonce');

        $this->client()->verifyIdToken($this->idToken([]), 'the-nonce');
    }

    public function testATokenForAnotherClientIsRejected(): void
    {
        $this->expectExceptionMessage('not issued for this client');

        $this->client()->verifyIdToken($this->idToken(['nonce' => 'n', 'aud' => 'someone-else']), 'n');
    }

    public function testATokenFromAnotherIssuerIsRejected(): void
    {
        $this->expectExceptionMessage('different provider');

        $this->client()->verifyIdToken($this->idToken(['nonce' => 'n', 'iss' => 'https://evil.example']), 'n');
    }

    public function testATokenSignedWithAForeignKeyIsRejected(): void
    {
        $foreign = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $token = JWT::encode($this->claims(['nonce' => 'n']), $foreign, 'RS256', 'meridian-test-key');

        $this->expectException(\Throwable::class);
        $this->client()->verifyIdToken($token, 'n');
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $token = $this->idToken(['nonce' => 'n', 'exp' => time() - 3600]);

        $this->expectException(\Throwable::class);
        $this->client()->verifyIdToken($token, 'n');
    }

    // ── Configuration ─────────────────────────────────────────────────

    public function testWithoutIssuerAndClientIdThereAreNoAccounts(): void
    {
        $root = $this->cacheDir . '/root';
        mkdir($root . '/config', 0o775, true);
        file_put_contents($root . '/config/oidc.php', '<?php return ["issuer" => "https://id.example"];');

        self::assertNull(OidcConfig::load($root));

        file_put_contents(
            $root . '/config/oidc.php',
            '<?php return ["issuer" => "https://id.example/", "client_id" => "meridian"];',
        );
        $config = OidcConfig::load($root);

        self::assertNotNull($config);
        self::assertSame('https://id.example', $config->issuer);
        self::assertContains('openid', $config->scopes);

        unlink($root . '/config/oidc.php');
        rmdir($root . '/config');
        rmdir($root);
    }

    public function testTheCallbackDefaultsToTheCurrentOrigin(): void
    {
        $config = new OidcConfig(self::ISSUER, self::CLIENT_ID, '');

        self::assertSame('https://meridian.example/auth/callback', $config->redirectUri('https://meridian.example'));
        self::assertSame(
            'https://fixed.example/cb',
            (new OidcConfig(self::ISSUER, self::CLIENT_ID, '', 'https://fixed.example/cb'))->redirectUri('https://x'),
        );
    }

    // ── Fixtures ──────────────────────────────────────────────────────

    private function client(): OidcClient
    {
        return new OidcClient(
            new OidcConfig(self::ISSUER, self::CLIENT_ID, 'secret'),
            $this->cacheDir,
            new FakeHttp([
                self::ISSUER . '/.well-known/openid-configuration' => [
                    'issuer' => self::ISSUER,
                    'authorization_endpoint' => self::ISSUER . '/authorize',
                    'token_endpoint' => self::ISSUER . '/token',
                    'jwks_uri' => self::ISSUER . '/jwks',
                ],
                self::ISSUER . '/jwks' => ['keys' => [$this->publicJwk()]],
            ]),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function idToken(array $overrides): string
    {
        return JWT::encode($this->claims($overrides), $this->key, 'RS256', 'meridian-test-key');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function claims(array $overrides): array
    {
        return $overrides + [
            'iss' => self::ISSUER,
            'sub' => 'subject-1',
            'aud' => self::CLIENT_ID,
            'email' => 'ada@example.org',
            'name' => 'Ada',
            'iat' => time(),
            'exp' => time() + 600,
        ];
    }

    /** @return array<string, string> */
    private function publicJwk(): array
    {
        $details = openssl_pkey_get_details($this->key);

        return [
            'kty' => 'RSA',
            'kid' => 'meridian-test-key',
            'alg' => 'RS256',
            'use' => 'sig',
            'n' => Random::base64Url($details['rsa']['n']),
            'e' => Random::base64Url($details['rsa']['e']),
        ];
    }
}
