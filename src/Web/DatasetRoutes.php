<?php

declare(strict_types=1);

namespace Meridian\Web;

use Meridian\Collection\Collections;
use Meridian\Collection\Duel;
use Meridian\Collection\Selector;
use Meridian\Edition\Builder;
use Meridian\Edition\Classifier;
use Meridian\Feed\ItemCache;
use Meridian\Registry\Registry;
use Meridian\Spectrum\AxisGrid;

/**
 * The pages that show what the dataset consists of: `/sources`,
 * `/publishers`, `/collections` and `/categories`. Returns null for
 * paths it does not own.
 */
final readonly class DatasetRoutes
{
    public function __construct(
        private Registry $registry,
        private Builder $builder,
        private ItemCache $cache,
        private string $collectionsFile,
    ) {
    }

    public function handle(Request $request, View $view): ?Response
    {
        if ($request->isPost()) {
            return null;
        }

        return match ($request->normalizedPath()) {
            '/sources' => $this->sources($view),
            '/publishers' => $this->publishers($view),
            '/collections' => $this->collections($view),
            '/categories' => $this->categories($view),
            default => null,
        };
    }

    private function sources(View $view): Response
    {
        $now = new \DateTimeImmutable();
        $byTopic = $this->builder->classifyFresh($this->registry, $this->cache->loadOrEmpty(), $now);

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
            'map_points' => SpectrumMap::points($this->registry->all()),
            'dataset_grid' => AxisGrid::count($this->registry->all()),
            'in_focus' => $inFocus,
            'perspective_counts' => $perspectiveCounts,
            'window_hours' => Builder::MAX_ITEM_AGE_HOURS,
        ]);
    }

    private function publishers(View $view): Response
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

    private function collections(View $view): Response
    {
        $collections = Collections::load($this->collectionsFile);
        $selector = new Selector();
        $now = new \DateTimeImmutable();
        $items = $this->cache->loadOrEmpty();

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

    private function categories(View $view): Response
    {
        $now = new \DateTimeImmutable();
        $byTopic = $this->builder->classifyFresh($this->registry, $this->cache->loadOrEmpty(), $now);

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
}
