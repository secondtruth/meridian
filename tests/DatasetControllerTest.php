<?php

declare(strict_types=1);

namespace Meridian\Tests;

use Meridian\Collection\Collections;
use Meridian\Edition\Builder;
use Meridian\Feed\Item;
use Meridian\Feed\ItemCache;
use Meridian\I18n\Translator;
use Meridian\Registry\Rating;
use Meridian\Registry\Registry;
use Meridian\Registry\Source;
use Meridian\Web\Controller\DatasetController;
use Meridian\Web\Request;
use Meridian\Web\Response;
use Meridian\Web\View;
use Meridian\Web\Viewer;
use PHPUnit\Framework\TestCase;

/**
 * Render passes over the dataset pages, so a missing message key or a
 * template variable fails the build rather than the page, plus the
 * fall-through contract for paths the group does not own.
 */
final class DatasetControllerTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/meridian-dataset-routes-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
        (new ItemCache($this->dir . '/items.json'))->save([
            new Item('mongabay', 'Rainforest story', 'https://example.org/rainforest', '', new \DateTimeImmutable('-2 hours')),
        ]);
        file_put_contents($this->dir . '/collections.yaml', <<<YAML
            - id: global-south
              sources: [mongabay]
              window_days: 7
              cap: 12
              per_source: 6
            YAML);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function dispatch(string $path, string $method = 'GET'): ?Response
    {
        $request = new Request($method, $path);
        $view = new View(
            self::ROOT . '/templates',
            new Translator('de', self::ROOT . '/translations'),
            $request,
            Viewer::anonymous(false),
        );
        $registry = new Registry([
            new Source(
                id: 'mongabay', name: 'Mongabay', country: 'US', languages: ['en'],
                type: 'online', ownership: 'foundation', publisher: 'Mongabay', funding: ['donations'],
                perspective: 'global-south', topics: ['climate'], homepage: '', feeds: ['https://x/rss'],
                rating: new Rating(-0.5, -1.5, 0.0, 'nonpartisan', 4, 'high', 'none', 'high'),
            ),
            new Source(
                id: 'dlf', name: 'Deutschlandfunk', country: 'DE', languages: ['de'],
                type: 'public-broadcaster', ownership: 'public-service', publisher: 'Deutschlandradio', funding: [],
                perspective: 'dach', topics: ['general'], homepage: '', feeds: ['https://x/feed2'],
                rating: new Rating(0.0, -0.5, 1.0, 'nonpartisan', 5, 'high', 'indirect', 'high'),
            ),
        ]);
        $routes = new DatasetController(
            $registry,
            new Builder(),
            new ItemCache($this->dir . '/items.json'),
            Collections::load($this->dir . '/collections.yaml'),
        );

        return $routes->handle($request, $view);
    }

    public function testSourcesPageListsTheDatasetWithFocusCounts(): void
    {
        $response = $this->dispatch('/sources');
        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Mongabay', $response->body);
        self::assertStringContainsString('Deutschlandfunk', $response->body);
    }

    public function testPublishersPageGroupsByPublisher(): void
    {
        $response = $this->dispatch('/publishers');
        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Deutschlandradio', $response->body);
    }

    public function testCollectionsPageSelectsFromTheCuratedLists(): void
    {
        $response = $this->dispatch('/collections');
        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Rainforest story', $response->body);
    }

    public function testCategoriesPageNamesTheSpecialists(): void
    {
        $response = $this->dispatch('/categories');
        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Mongabay', $response->body);
    }

    public function testUnclaimedPathsAndPostsAreLeftToTheApp(): void
    {
        self::assertNull($this->dispatch('/'));
        self::assertNull($this->dispatch('/methodology'));
        self::assertNull($this->dispatch('/archive'));
        self::assertNull($this->dispatch('/sources', method: 'POST'));
    }
}
