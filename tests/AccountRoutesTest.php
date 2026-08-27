<?php

declare(strict_types=1);

namespace Meridian\Tests;

use Meridian\Account\Database;
use Meridian\Account\Sessions;
use Meridian\Account\Store;
use Meridian\Account\User;
use Meridian\Auth\OidcConfig;
use Meridian\Edition\Builder;
use Meridian\Feed\Item;
use Meridian\Feed\ItemCache;
use Meridian\I18n\Translator;
use Meridian\Registry\Rating;
use Meridian\Registry\Registry;
use Meridian\Registry\Source;
use Meridian\Web\AccountRoutes;
use Meridian\Web\Request;
use Meridian\Web\View;
use Meridian\Web\Viewer;
use PHPUnit\Framework\TestCase;

/**
 * Route-level checks: the guards that stand in front of every account
 * action, plus a render pass over the new templates so a missing message
 * key fails the build rather than the page.
 */
final class AccountRoutesTest extends TestCase
{
    private const ARTICLE_URL = 'https://example.org/climate/story';
    private const ROOT = __DIR__ . '/..';

    private Store $store;
    private User $user;
    private string $sessionToken;
    private string $csrf;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/meridian-routes-test-' . bin2hex(random_bytes(6));
        mkdir($this->cacheDir, 0o775, true);
        (new ItemCache($this->cacheDir . '/items.json'))->save([
            new Item('mongabay', 'Rainforest story', self::ARTICLE_URL, '', new \DateTimeImmutable('-2 hours')),
        ]);

        $this->store = new Store(Database::inMemory());
        $now = new \DateTimeImmutable();
        $this->user = $this->store->accounts->upsert('https://id.example', 'sub', 'ada@example.org', 'Ada', $now);
        $this->sessionToken = $this->store->sessions->start($this->user->id, $now);
        $this->csrf = $this->store->sessions->lookup($this->sessionToken, $now)->csrfToken;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->cacheDir);
    }

    private function registry(): Registry
    {
        return new Registry([new Source(
            id: 'mongabay', name: 'Mongabay', country: 'US', languages: ['en'],
            type: 'online', ownership: 'foundation', publisher: 'Mongabay', funding: ['donations'],
            perspective: 'global-south', topics: ['climate'], homepage: '', feeds: ['https://x/rss'],
            rating: new Rating(-0.5, -1.5, 0.0, 'nonpartisan', 4, 'high', 'none', 'high'),
        )]);
    }

    private function routes(bool $accountsConfigured = true): AccountRoutes
    {
        return new AccountRoutes(
            $this->store,
            $this->registry(),
            new Builder(),
            new ItemCache($this->cacheDir . '/items.json'),
            $accountsConfigured ? new OidcConfig('https://id.example', 'meridian', 'secret') : null,
            $this->cacheDir,
        );
    }

    private function viewer(bool $signedIn, bool $accountsEnabled = true): Viewer
    {
        if (!$signedIn) {
            return Viewer::anonymous($accountsEnabled);
        }

        $session = $this->store->sessions->lookup($this->sessionToken, new \DateTimeImmutable());

        return new Viewer(
            $this->store->accounts->preferences($this->user->id),
            $accountsEnabled,
            $this->user,
            $session,
        );
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    private function dispatch(
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        bool $signedIn = true,
        bool $accountsConfigured = true,
        string $locale = 'de',
    ): ?\Meridian\Web\Response {
        $request = new Request($method, $path, $query, $body, cookies: [Sessions::COOKIE => $this->sessionToken]);
        $viewer = $this->viewer($signedIn, $accountsConfigured);
        $view = new View(
            self::ROOT . '/templates',
            new Translator($locale, self::ROOT . '/translations'),
            $request,
            $viewer,
        );

        return $this->routes($accountsConfigured)->handle($request, $view, $viewer);
    }

    public function testPathsOutsideTheAccountAreaAreNotClaimed(): void
    {
        self::assertNull($this->dispatch('GET', '/'));
        self::assertNull($this->dispatch('GET', '/sources'));
        self::assertNull($this->dispatch('GET', '/methodology'));
    }

    public function testAnonymousVisitorsAreSentToSignIn(): void
    {
        foreach (['/balance', '/watchlist', '/account'] as $path) {
            $response = $this->dispatch('GET', $path, signedIn: false);

            self::assertSame(303, $response->status, $path);
            self::assertSame(
                '/login?return=' . rawurlencode($path),
                $response->headers()['Location'],
            );
        }
    }

    public function testWithoutAProviderTheAccountAreaSaysSoInsteadOfRedirecting(): void
    {
        $response = $this->dispatch('GET', '/balance', signedIn: false, accountsConfigured: false);

        self::assertSame(404, $response->status);
        self::assertStringContainsString('ohne Konten', $response->body);
    }

    public function testStateChangingRequestsNeedTheSessionCsrfToken(): void
    {
        $response = $this->dispatch('POST', '/account', body: ['locale' => 'en']);

        self::assertSame(400, $response->status);
        self::assertSame('de', $this->store->accounts->preferences($this->user->id)->locale);
    }

    public function testPreferencesAreSavedWithAValidToken(): void
    {
        $response = $this->dispatch('POST', '/account', body: [
            '_csrf' => $this->csrf,
            'locale' => 'en',
            'mode' => 'full',
            'muted_topics' => ['peace'],
            'track_reading' => '1',
            'retention_days' => '30',
        ]);

        self::assertSame(303, $response->status);
        self::assertSame('/account?saved=1', $response->headers()['Location']);

        $preferences = $this->store->accounts->preferences($this->user->id);
        self::assertSame('en', $preferences->locale);
        self::assertSame(['peace'], $preferences->mutedTopics);
        self::assertFalse($preferences->dailyLimit);
        self::assertSame(30, $preferences->retentionDays);
    }

    public function testOpeningAnArticleIsRecordedOnce(): void
    {
        $response = $this->dispatch('POST', '/read', body: ['_csrf' => $this->csrf, 'url' => self::ARTICLE_URL]);

        self::assertSame(204, $response->status);
        $reads = $this->store->reads->since($this->user->id, new \DateTimeImmutable('-1 day'));
        self::assertCount(1, $reads);
        self::assertSame('mongabay', $reads[0]->sourceId);
        self::assertSame('climate', $reads[0]->topic);
    }

    /** Reading history must not be forgeable — the source and topic come from the cache. */
    public function testAnUnknownLinkIsNotRecorded(): void
    {
        $response = $this->dispatch('POST', '/read', body: [
            '_csrf' => $this->csrf,
            'url' => 'https://evil.example/made-up',
        ]);

        self::assertSame(204, $response->status);
        self::assertSame([], $this->store->reads->since($this->user->id, new \DateTimeImmutable('-1 day')));
    }

    public function testReadTrackingRespectsTheCsrfTokenAndThePreference(): void
    {
        $this->dispatch('POST', '/read', body: ['_csrf' => 'wrong', 'url' => self::ARTICLE_URL]);
        self::assertSame([], $this->store->reads->since($this->user->id, new \DateTimeImmutable('-1 day')));

        $this->store->accounts->savePreferences(
            $this->user->id,
            new \Meridian\Account\Preferences(trackReading: false),
        );
        $this->dispatch('POST', '/read', body: ['_csrf' => $this->csrf, 'url' => self::ARTICLE_URL]);
        self::assertSame([], $this->store->reads->since($this->user->id, new \DateTimeImmutable('-1 day')));
    }

    public function testSavingAndRemovingFromTheWatchlist(): void
    {
        $response = $this->dispatch('POST', '/watchlist', body: [
            '_csrf' => $this->csrf,
            'action' => 'add',
            'url' => self::ARTICLE_URL,
            'return' => '/?mode=full',
        ]);

        self::assertSame(303, $response->status);
        self::assertSame('/?mode=full', $response->headers()['Location']);
        self::assertTrue($this->store->watchlist->contains($this->user->id, self::ARTICLE_URL));

        $this->dispatch('POST', '/watchlist', body: [
            '_csrf' => $this->csrf,
            'action' => 'remove',
            'url' => self::ARTICLE_URL,
        ]);
        self::assertFalse($this->store->watchlist->contains($this->user->id, self::ARTICLE_URL));
    }

    public function testTheReturnTargetCannotLeaveTheSite(): void
    {
        $response = $this->dispatch('POST', '/watchlist', body: [
            '_csrf' => $this->csrf,
            'action' => 'remove',
            'url' => self::ARTICLE_URL,
            'return' => 'https://evil.example/phish',
        ]);

        self::assertSame('/', $response->headers()['Location']);
    }

    public function testReportsAreStoredAgainstTheClassifiedArticle(): void
    {
        $response = $this->dispatch('POST', '/report', body: [
            '_csrf' => $this->csrf,
            'url' => self::ARTICLE_URL,
            'kind' => 'topic',
            'note' => 'belongs under peace',
        ]);

        self::assertSame(200, $response->status);
        $open = $this->store->reports->open();
        self::assertCount(1, $open);
        self::assertSame('mongabay', $open[0]['source_id']);
        self::assertSame('climate', $open[0]['topic']);
    }

    public function testTheExportIsDownloadableJson(): void
    {
        $response = $this->dispatch('GET', '/account/export');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('application/json', $response->headers()['Content-Type']);
        self::assertStringContainsString('meridian-export.json', $response->headers()['Content-Disposition']);
        self::assertIsArray(json_decode($response->body, true));
    }

    public function testDeletingTheAccountClearsTheSessionCookie(): void
    {
        $response = $this->dispatch('POST', '/account/delete', body: ['_csrf' => $this->csrf, 'confirm' => '1']);

        self::assertSame(200, $response->status);
        self::assertNull($this->store->accounts->find($this->user->id));
        self::assertSame(Sessions::COOKIE, $response->cookies()[0]->name);
        self::assertSame('', $response->cookies()[0]->value);
    }

    public function testDeletionNeedsTheConfirmationBox(): void
    {
        $response = $this->dispatch('POST', '/account/delete', body: ['_csrf' => $this->csrf]);

        self::assertSame(303, $response->status);
        self::assertNotNull($this->store->accounts->find($this->user->id));
    }

    public function testSigningOutEndsTheSession(): void
    {
        $response = $this->dispatch('POST', '/logout', body: ['_csrf' => $this->csrf]);

        self::assertSame(303, $response->status);
        self::assertNull($this->store->sessions->lookup($this->sessionToken, new \DateTimeImmutable()));
    }

    /**
     * Both catalogs must carry every new key — a gap would surface as the
     * raw dot-notation key in the page.
     */
    public function testAccountPagesRenderInBothLocales(): void
    {
        foreach (['de', 'en'] as $locale) {
            foreach (['/account', '/balance', '/watchlist'] as $path) {
                $body = $this->dispatch('GET', $path, locale: $locale)->body;

                self::assertDoesNotMatchRegularExpression(
                    '/\b(account|balance|watchlist|auth|report)\.[a-z_]+\b/',
                    strip_tags($body),
                    "{$path} ({$locale}) shows an untranslated key",
                );
            }
        }
    }

    /** Renders every new template against the real catalogs. */
    public function testAccountPagesRender(): void
    {
        $this->store->reads->record(
            $this->user->id,
            (new Builder())->findFresh(
                $this->registry(),
                (new ItemCache($this->cacheDir . '/items.json'))->load(),
                new \DateTimeImmutable(),
                self::ARTICLE_URL,
            ),
            new \DateTimeImmutable(),
        );

        foreach (['/account', '/balance', '/watchlist'] as $path) {
            $response = $this->dispatch('GET', $path);

            self::assertSame(200, $response->status, $path);
            self::assertStringContainsString('</html>', $response->body, $path);
            self::assertStringNotContainsString('{{', $response->body, $path);
        }

        $report = $this->dispatch('GET', '/report', query: ['url' => self::ARTICLE_URL]);
        self::assertSame(200, $report->status);
        self::assertStringContainsString('Mongabay', $report->body);
    }

    public function testBalancePeriodIsLimitedToTheOfferedRanges(): void
    {
        $response = $this->dispatch('GET', '/balance', query: ['days' => '9999']);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('30', $response->body);
    }
}
