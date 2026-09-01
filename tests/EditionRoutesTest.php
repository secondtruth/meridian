<?php

declare(strict_types=1);

namespace Meridian\Tests;

use Meridian\Account\Database;
use Meridian\Account\Store;
use Meridian\Edition\Archive;
use Meridian\Edition\Article;
use Meridian\Edition\Builder;
use Meridian\Edition\Edition;
use Meridian\Edition\Mode;
use Meridian\Edition\Section;
use Meridian\Feed\Item;
use Meridian\Feed\ItemCache;
use Meridian\I18n\Translator;
use Meridian\Registry\Rating;
use Meridian\Registry\Registry;
use Meridian\Registry\Source;
use Meridian\Spectrum\Diversity;
use Meridian\Web\EditionRoutes;
use Meridian\Web\Request;
use Meridian\Web\Response;
use Meridian\Web\View;
use Meridian\Web\Viewer;
use PHPUnit\Framework\TestCase;

/**
 * Render passes over the edition and archive pages, plus the fall-through
 * contract: unclaimed paths and unknown archive days return null so App
 * answers with its 404.
 */
final class EditionRoutesTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/meridian-edition-routes-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/archive', 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/archive/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir . '/archive');
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private static function source(): Source
    {
        return new Source(
            id: 'mongabay', name: 'Mongabay', country: 'US', languages: ['en'],
            type: 'online', ownership: 'foundation', publisher: 'Mongabay', funding: ['donations'],
            perspective: 'global-south', topics: ['climate'], homepage: '', feeds: ['https://x/rss'],
            rating: new Rating(-0.5, -1.5, 0.0, 'nonpartisan', 4, 'high', 'none', 'high'),
        );
    }

    private function cacheItems(): void
    {
        (new ItemCache($this->dir . '/items.json'))->save([
            new Item('mongabay', 'Rainforest story', 'https://example.org/rainforest', 'A summary.', new \DateTimeImmutable('-2 hours')),
        ]);
    }

    /** @param array<string, string> $cookies */
    private function dispatch(string $path, array $query = [], array $cookies = [], string $method = 'GET'): ?Response
    {
        $request = new Request($method, $path, $query, cookies: $cookies);
        $viewer = Viewer::anonymous(false);
        $view = new View(
            self::ROOT . '/templates',
            new Translator('de', self::ROOT . '/translations'),
            $request,
            $viewer,
        );
        $routes = new EditionRoutes(
            new Registry([self::source()]),
            new Builder(),
            new ItemCache($this->dir . '/items.json'),
            new Archive($this->dir . '/archive'),
            new Store(Database::inMemory()),
        );

        return $routes->handle($request, $view, $viewer);
    }

    public function testEditionRendersTheFreshArticles(): void
    {
        $this->cacheItems();

        $response = $this->dispatch('/');
        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Rainforest story', $response->body);
    }

    public function testEditionRendersItsEmptyStateBeforeTheFirstFetch(): void
    {
        $response = $this->dispatch('/');
        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('Rainforest story', $response->body);
    }

    public function testWelcomeIsShownOnceAndDismissedByCookie(): void
    {
        $first = $this->dispatch('/')->body;
        $dismissed = $this->dispatch('/', query: ['welcome' => 'off']);
        $returning = $this->dispatch('/', cookies: ['meridian-welcome' => 'seen'])->body;

        self::assertSame(['meridian-welcome'], array_map(fn ($c) => $c->name, $dismissed->cookies()));
        self::assertStringContainsString('<section class="welcome"', $first);
        self::assertStringNotContainsString('<section class="welcome"', $dismissed->body);
        self::assertStringNotContainsString('<section class="welcome"', $returning);
    }

    public function testArchiveListsAndRestoresFrozenDays(): void
    {
        $article = new Article(
            new Item('mongabay', 'Frozen story', 'https://example.org/frozen', 'Kept.', new \DateTimeImmutable('2026-08-27 08:00:00')),
            self::source(),
            'climate',
        );
        (new Archive($this->dir . '/archive'))->save(new Edition(
            new \DateTimeImmutable('2026-08-27 12:00:00'),
            Mode::Compact,
            [new Section('climate', [$article], new Diversity())],
        ));

        $index = $this->dispatch('/archive');
        self::assertNotNull($index);
        self::assertStringContainsString('/archive/2026-08-27', $index->body);

        $day = $this->dispatch('/archive/2026-08-27');
        self::assertNotNull($day);
        self::assertSame(200, $day->status);
        self::assertStringContainsString('Frozen story', $day->body);
    }

    public function testUnknownDaysAndUnclaimedPathsAreLeftToTheApp(): void
    {
        self::assertNull($this->dispatch('/archive/2000-01-01'));
        self::assertNull($this->dispatch('/archive/not-a-date'));
        self::assertNull($this->dispatch('/sources'));
        self::assertNull($this->dispatch('/methodology'));
        self::assertNull($this->dispatch('/', method: 'POST'));
    }
}
