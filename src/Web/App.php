<?php

declare(strict_types=1);

namespace Meridian\Web;

use Meridian\Account\Store;
use Meridian\Auth\OidcConfig;
use Meridian\Collection\Collections;
use Meridian\Collection\Duel;
use Meridian\Collection\Selector;
use Meridian\Edition\Archive;
use Meridian\Edition\Article;
use Meridian\Edition\Builder;
use Meridian\Edition\Classifier;
use Meridian\Edition\Mode;
use Meridian\Feed\Item;
use Meridian\Feed\ItemCache;
use Meridian\I18n\Translator;
use Meridian\Registry\Registry;
use Meridian\Spectrum\AxisGrid;

/** Request-scoped wiring for all pages — Meridian is a website. */
final class App
{
    private const LANG_COOKIE = 'meridian-lang';
    private const WELCOME_COOKIE = 'meridian-welcome';

    private readonly Registry $registry;
    private readonly ItemCache $cache;
    private readonly Builder $builder;
    private readonly Store $store;
    private readonly ?OidcConfig $oidc;

    public function __construct(private readonly string $rootDir)
    {
        $this->registry = Registry::load($rootDir . '/data/sources');
        $this->cache = new ItemCache($rootDir . '/data/cache/items.json');
        $this->builder = new Builder();
        $this->store = Store::at($rootDir);
        $this->oidc = OidcConfig::load($rootDir);
    }

    public function handle(Request $request): Response
    {
        $viewer = $this->resolveViewer($request);

        $requested = $request->query('lang');
        $locale = Translator::resolveLocale(
            $requested ?? ($viewer->isSignedIn()
                ? $viewer->preferences->locale
                : $request->cookie(self::LANG_COOKIE)),
        );
        if ($requested !== null && $viewer->isSignedIn() && $locale !== $viewer->preferences->locale) {
            $preferences = $viewer->preferences->withLocale($locale);
            $this->store->accounts->savePreferences($viewer->user->id, $preferences);
            $viewer = $viewer->withPreferences($preferences);
        }

        $view = new View(
            $this->rootDir . '/templates',
            new Translator($locale, $this->rootDir . '/translations'),
            $request,
            $viewer,
            $this->mirroredFavicons(),
        );

        $response = $this->route($request, $view, $viewer);

        if ($requested !== null) {
            $response = $response->withCookie(new Cookie(
                name: self::LANG_COOKIE,
                value: $locale,
                expires: time() + 31536000,
                secure: $request->secure,
            ));
        }

        return $response;
    }

    private function route(Request $request, View $view, Viewer $viewer): Response
    {
        $accountRoutes = new AccountRoutes(
            $this->store,
            $this->registry,
            $this->builder,
            $this->cache,
            $this->oidc,
            $this->rootDir . '/data/cache',
        );
        $response = $accountRoutes->handle($request, $view, $viewer);
        if ($response !== null) {
            return $response;
        }

        $response = new LegalPages($this->oidc)->handle($request, $view);
        if ($response !== null) {
            return $response;
        }

        if ($request->isPost()) {
            return $this->renderNotFound($view);
        }

        if (preg_match('#\A/archive/(\d{4}-\d{2}-\d{2})\z#', $request->normalizedPath(), $m) === 1) {
            return $this->renderArchiveDay($view, $m[1]);
        }

        return match ($request->normalizedPath()) {
            '/' => $this->renderEdition($request, $view, $viewer),
            '/sources' => $this->renderSources($view),
            '/publishers' => $this->renderPublishers($view),
            '/collections' => $this->renderCollections($view),
            '/categories' => $this->renderCategories($view),
            '/archive' => $this->renderArchiveIndex($view),
            '/methodology' => $this->renderMethodology($view),
            default => $this->renderNotFound($view),
        };
    }

    /**
     * Resolves who is reading. A missing account store, an unconfigured
     * provider or an unknown cookie all end up in the same place: an
     * anonymous reader with default preferences.
     */
    private function resolveViewer(Request $request): Viewer
    {
        $enabled = $this->oidc !== null;
        $token = $request->cookie(\Meridian\Account\Sessions::COOKIE);
        if (!$enabled || $token === null || !$this->store->db->exists()) {
            return Viewer::anonymous($enabled);
        }

        $session = $this->store->sessions->lookup($token, new \DateTimeImmutable());
        $user = $session === null ? null : $this->store->accounts->find($session->userId);
        if ($session === null || $user === null) {
            return Viewer::anonymous($enabled);
        }

        return new Viewer(
            preferences: $this->store->accounts->preferences($user->id),
            accountsEnabled: true,
            user: $user,
            session: $session,
        );
    }

    private function renderEdition(Request $request, View $view, Viewer $viewer): Response
    {
        $mode = Mode::fromQuery($request->query('mode') ?? $viewer->preferences->mode->value);
        $now = new \DateTimeImmutable();
        $items = $this->cachedItems();
        $edition = $this->builder->build($this->registry, $items, $now, $mode, $viewer->preferences->mutedTopics);

        $links = [];
        foreach ($edition->sections as $section) {
            foreach ($section->articles as $article) {
                $links[] = $article->item->link;
            }
        }

        $read = [];
        $saved = [];
        $readToday = 0;
        if ($viewer->isSignedIn()) {
            $userId = $viewer->user->id;
            $read = $viewer->preferences->trackReading
                ? $this->store->reads->readAmong($userId, $links)
                : [];
            $saved = $this->store->watchlist->savedAmong($userId, $links);
            $readToday = $viewer->preferences->trackReading
                ? $this->store->reads->countSince($userId, $now->setTime(0, 0))
                : 0;
        }

        // First-visit welcome: shown until dismissed via /?welcome=off,
        // which persists a cookie — no account and no JavaScript needed.
        // Signed-in readers are past their first visit by definition.
        $welcomeOff = $request->query('welcome') === 'off';

        $response = $view->render('edition.html.twig', [
            'nav_active' => 'edition',
            'edition' => $edition,
            'mode' => $mode,
            'modes' => Mode::cases(),
            'date_human' => $view->localizedDate($now),
            'fetched_at' => $this->cache->lastFetchedAt(),
            'source_count' => count($edition->sourceIds()),
            'perspective_count' => count($edition->perspectives()),
            'max_total' => Builder::MAX_ITEMS_TOTAL,
            'window_hours' => Builder::MAX_ITEM_AGE_HOURS,
            'no_data' => $items === [],
            'read_urls' => $read,
            'saved_urls' => $saved,
            'read_today' => $readToday,
            'daily_limit_reached' => $viewer->preferences->dailyLimit
                && $readToday >= Builder::MAX_ITEMS_TOTAL,
            'muted_topics' => $viewer->preferences->mutedTopics,
            'show_welcome' => !$welcomeOff && !$viewer->isSignedIn()
                && $request->cookie(self::WELCOME_COOKIE) === null,
        ]);

        if ($welcomeOff) {
            $response = $response->withCookie(new Cookie(
                name: self::WELCOME_COOKIE,
                value: 'seen',
                expires: time() + 31536000,
                secure: $request->secure,
            ));
        }

        return $response;
    }

    private function renderSources(View $view): Response
    {
        $now = new \DateTimeImmutable();
        $byTopic = $this->builder->classifyFresh($this->registry, $this->cachedItems(), $now);

        $inFocus = [];
        foreach ($byTopic as $articles) {
            foreach ($articles as $article) {
                $inFocus[$article->source->id] = ($inFocus[$article->source->id] ?? 0) + 1;
            }
        }

        $perspectiveCounts = [];
        foreach ($this->registry->all() as $source) {
            $perspectiveCounts[$source->perspective] = ($perspectiveCounts[$source->perspective] ?? 0) + 1;
        }

        return $view->render('sources.html.twig', [
            'nav_active' => 'sources',
            'sources' => $this->registry->all(),
            'map_points' => $this->spectrumMapPoints(),
            'dataset_grid' => AxisGrid::count($this->registry->all()),
            'in_focus' => $inFocus,
            'perspective_counts' => $perspectiveCounts,
            'window_hours' => Builder::MAX_ITEM_AGE_HOURS,
        ]);
    }

    private function renderPublishers(View $view): Response
    {
        $publishers = $this->registry->publishers();
        $groups = array_values(array_filter($publishers, fn ($p) => $p->isGroup()));

        return $view->render('publishers.html.twig', [
            'nav_active' => 'publishers',
            'publishers' => $publishers,
            'publisher_count' => count($publishers),
            'source_count' => $this->registry->count(),
            'group_count' => count($groups),
        ]);
    }

    private function renderArchiveIndex(View $view): Response
    {
        $archive = new Archive($this->rootDir . '/data/archive');

        $days = [];
        foreach ($archive->dates() as $date) {
            $days[] = [
                'date' => $date,
                'date_human' => $view->localizedDate(new \DateTimeImmutable($date)),
            ];
        }

        return $view->render('archive.html.twig', [
            'nav_active' => 'archive',
            'days' => $days,
        ]);
    }

    /**
     * A frozen edition. Articles whose source is still in the dataset
     * render as full cards (with the *current* rating — labelled as
     * such); articles from since-removed sources render plainly.
     */
    private function renderArchiveDay(View $view, string $date): Response
    {
        $data = (new Archive($this->rootDir . '/data/archive'))->load($date);
        if ($data === null) {
            return $this->renderNotFound($view);
        }

        $sections = [];
        foreach ($data['sections'] as $section) {
            $entries = [];
            foreach ($section['articles'] as $stored) {
                $source = $this->registry->get($stored['source_id']);
                $tellings = [];
                foreach ($stored['also_covered_by'] ?? [] as $member) {
                    $memberSource = $this->registry->get($member['source_id']);
                    if ($memberSource === null) {
                        continue;
                    }
                    $tellings[] = new Article(
                        new Item(
                            sourceId: $member['source_id'],
                            title: $member['title'],
                            link: $member['link'],
                            summary: '',
                            published: new \DateTimeImmutable($member['published']),
                        ),
                        $memberSource,
                        $section['topic'],
                    );
                }
                $entries[] = [
                    'stored' => $stored,
                    'article' => $source === null ? null : new Article(
                        new Item(
                            sourceId: $stored['source_id'],
                            title: $stored['title'],
                            link: $stored['link'],
                            summary: $stored['summary'],
                            published: new \DateTimeImmutable($stored['published']),
                        ),
                        $source,
                        $section['topic'],
                        $tellings,
                    ),
                ];
            }
            $sections[] = ['topic' => $section['topic'], 'entries' => $entries];
        }

        return $view->render('archive_day.html.twig', [
            'nav_active' => 'archive',
            'date' => $date,
            'date_human' => $view->localizedDate(new \DateTimeImmutable($date)),
            'sections' => $sections,
        ]);
    }

    private function renderCollections(View $view): Response
    {
        $collections = Collections::load($this->rootDir . '/data/collections.yaml');
        $selector = new Selector();
        $now = new \DateTimeImmutable();
        $items = $this->cachedItems();

        $entries = [];
        foreach ($collections->all() as $collection) {
            $entries[] = [
                'collection' => $collection,
                'articles' => $selector->select($collection, $this->registry, $items, $now),
            ];
        }

        return $view->render('collections.html.twig', [
            'nav_active' => 'collections',
            'collections' => $entries,
            'duel' => Duel::select($this->builder->classifyFresh($this->registry, $items, $now)),
            'duel_cap' => Duel::CAP,
        ]);
    }

    private function renderCategories(View $view): Response
    {
        $now = new \DateTimeImmutable();
        $byTopic = $this->builder->classifyFresh($this->registry, $this->cachedItems(), $now);

        $topics = [];
        foreach (Classifier::TOPIC_ORDER as $topic) {
            $specialists = array_values(array_filter(
                $this->registry->all(),
                fn ($s) => $s->specialistTopic() === $topic,
            ));
            $topics[] = [
                'id' => $topic,
                'article_count' => count($byTopic[$topic] ?? []),
                'latest' => array_slice($byTopic[$topic] ?? [], 0, 3),
                'specialists' => $specialists,
                'keywords_de' => Classifier::KEYWORDS_DE[$topic],
                'keywords_en' => Classifier::KEYWORDS_EN[$topic],
            ];
        }

        return $view->render('categories.html.twig', [
            'nav_active' => 'categories',
            'topics' => $topics,
            'window_hours' => Builder::MAX_ITEM_AGE_HOURS,
        ]);
    }

    private function renderMethodology(View $view): Response
    {
        $translator = $view->translator;

        return $view->render('methodology.html.twig', [
            'nav_active' => 'methodology',
            'why' => $translator->get('methodology.why'),
            'more' => $translator->get('methodology.more'),
            'selection' => $translator->get('methodology.selection'),
            'limits' => $translator->get('methodology.limits'),
            'glossary' => $translator->get('methodology.glossary'),
            'axes' => [
                ['key' => 'economic', 'title' => $translator->t('axis.economic_title'), 'bands' => $translator->get('axis.economic')],
                ['key' => 'cultural', 'title' => $translator->t('axis.cultural_title'), 'bands' => $translator->get('axis.cultural')],
                ['key' => 'eu', 'title' => $translator->t('axis.eu_title'), 'bands' => $translator->get('axis.eu')],
            ],
        ]);
    }

    private function renderNotFound(View $view): Response
    {
        return $view->render('notfound.html.twig', [], 404);
    }

    /**
     * Icons mirrored by `favicons:fetch` — self-hosted, so a card never
     * triggers a third-party request. An empty directory is the normal
     * state before the first cron run.
     *
     * @return array<string, string> source id => filename
     */
    private function mirroredFavicons(): array
    {
        $icons = [];
        foreach (glob($this->rootDir . '/public/favicons/*.*') ?: [] as $path) {
            $filename = basename($path);
            $icons[pathinfo($filename, PATHINFO_FILENAME)] = $filename;
        }

        return $icons;
    }

    /** @return list<Item> */
    private function cachedItems(): array
    {
        try {
            return $this->cache->load();
        } catch (\RuntimeException) {
            return [];
        }
    }

    /**
     * Precomputes spectrum-map coordinates (economic → x, cultural → y,
     * TAN up) with greedy label collision avoidance: labels near the
     * right edge flip to the left of their dot, overlapping labels are
     * nudged downwards.
     *
     * @return list<array{source: \Meridian\Registry\Source, cx: float, cy: float,
     *                    lx: float, ly: float, anchor: string}>
     */
    private function spectrumMapPoints(): array
    {
        $points = [];
        foreach ($this->registry->all() as $source) {
            $cx = 50.0 + ($source->rating->economic + 3.0) / 6.0 * 640.0;
            $cy = 30.0 + (3.0 - $source->rating->cultural) / 6.0 * 380.0;
            $flip = $cx > 600.0;
            $points[] = [
                'source' => $source,
                'cx' => $cx,
                'cy' => $cy,
                'lx' => $flip ? $cx - 12.0 : $cx + 12.0,
                'ly' => $cy + 4.0,
                'anchor' => $flip ? 'end' : 'start',
            ];
        }

        usort($points, fn ($a, $b) => $a['ly'] <=> $b['ly']);
        $labelWidth = fn (array $p): float => strlen($p['source']->id) * 6.5;
        for ($pass = 0; $pass < 3; ++$pass) {
            foreach ($points as $i => $p) {
                foreach (array_slice($points, 0, $i) as $other) {
                    $overlapY = abs($p['ly'] - $other['ly']) < 13.0;
                    $aStart = $p['anchor'] === 'end' ? $p['lx'] - $labelWidth($p) : $p['lx'];
                    $bStart = $other['anchor'] === 'end' ? $other['lx'] - $labelWidth($other) : $other['lx'];
                    $overlapX = $aStart < $bStart + $labelWidth($other) && $bStart < $aStart + $labelWidth($p);
                    if ($overlapY && $overlapX) {
                        $points[$i]['ly'] = $other['ly'] + 13.0;
                        $p = $points[$i];
                    }
                }
            }
        }

        return $points;
    }
}
