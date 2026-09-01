<?php

declare(strict_types=1);

namespace Meridian\Edition;

use Meridian\Feed\Item;
use Meridian\Registry\Registry;
use Meridian\Spectrum\Band;
use Meridian\Spectrum\Diversity;

/**
 * Builds an edition from cached feed items: topic-classified,
 * perspective-balanced, hard-capped in compact mode. The caps and the
 * absence of any feed mechanics are the product's doom-scrolling
 * protection and must not be "optimised away".
 */
final class Builder
{
    public const MAX_ITEMS_TOTAL = 20;
    public const MAX_ITEMS_PER_TOPIC = 4;
    public const MAX_ITEM_AGE_HOURS = 48;
    public const SPECIALIST_MAX_ITEM_AGE_HOURS = 336;

    public function __construct(
        private readonly Classifier $classifier = new Classifier(),
        private readonly StoryClusterer $clusterer = new StoryClusterer(),
    ) {
    }

    /**
     * @param list<Item>   $items
     * @param list<string> $mutedTopics topics the reader switched off — never
     *                                  perspectives or spectrum positions
     */
    public function build(
        Registry $registry,
        array $items,
        \DateTimeImmutable $now,
        Mode $mode,
        array $mutedTopics = [],
    ): Edition {
        $byTopic = $this->classifyFresh($registry, $items, $now);
        foreach ($mutedTopics as $topic) {
            unset($byTopic[$topic]);
        }

        return match ($mode) {
            Mode::Compact => $this->buildCompact($byTopic, $now),
            Mode::Full => $this->buildFull($byTopic, $now),
        };
    }

    /**
     * Compact: greedy diverse selection under the hard caps, at most one
     * article per source per topic. Every non-empty topic first gets a
     * fair base quota (20 ÷ topics) so late topics in TOPIC_ORDER cannot
     * be crowded out; leftover slots first go where they close a reported
     * blind spot, then round-robin up to the per-topic cap.
     *
     * @param array<string, list<Article>> $byTopic
     */
    private function buildCompact(array $byTopic, \DateTimeImmutable $now): Edition
    {
        $topics = array_values(array_filter(
            Classifier::TOPIC_ORDER,
            fn (string $t) => ($byTopic[$t] ?? []) !== [],
        ));
        if ($topics === []) {
            return new Edition($now, Mode::Compact, []);
        }

        $globalDiversity = new Diversity();
        $quota = min(self::MAX_ITEMS_PER_TOPIC, intdiv(self::MAX_ITEMS_TOTAL, count($topics)));
        $perspectives = [];
        foreach ($topics as $topic) {
            $perspectives[$topic] = $this->candidatePerspectives($byTopic[$topic]);
        }

        /** @var array<string, array{selected: list<Article>, used: array<string, true>, diversity: Diversity}> $state */
        $state = [];
        $total = 0;
        foreach ($topics as $topic) {
            $state[$topic] = ['selected' => [], 'used' => [], 'diversity' => new Diversity()];
            for ($i = 0; $i < $quota; ++$i) {
                if (!$this->selectOne($byTopic[$topic], $state[$topic], $globalDiversity)) {
                    break;
                }
                ++$total;
            }
        }

        // Pass 2 — blindspot-directed surplus: spare capacity first goes
        // where it closes a gap the edition would otherwise report
        // (rating-system.md §5). Sections under two articles report no
        // blind spots, so they are only topped up by the fallback pass.
        $progress = true;
        while ($total < self::MAX_ITEMS_TOTAL && $progress) {
            $progress = false;
            foreach ($topics as $topic) {
                if ($total === self::MAX_ITEMS_TOTAL) {
                    break;
                }
                if (count($state[$topic]['selected']) >= self::MAX_ITEMS_PER_TOPIC) {
                    continue;
                }
                $closer = $this->blindspotCloser($topic, $state[$topic], $perspectives[$topic]);
                if ($closer !== null && $this->selectOne($byTopic[$topic], $state[$topic], $globalDiversity, $closer)) {
                    ++$total;
                    $progress = true;
                }
            }
        }

        // Pass 3 — round-robin fallback for whatever capacity remains.
        $progress = true;
        while ($total < self::MAX_ITEMS_TOTAL && $progress) {
            $progress = false;
            foreach ($topics as $topic) {
                if ($total === self::MAX_ITEMS_TOTAL) {
                    break;
                }
                if (count($state[$topic]['selected']) >= self::MAX_ITEMS_PER_TOPIC) {
                    continue;
                }
                if ($this->selectOne($byTopic[$topic], $state[$topic], $globalDiversity)) {
                    ++$total;
                    $progress = true;
                }
            }
        }

        $sections = [];
        foreach ($topics as $topic) {
            if ($state[$topic]['selected'] === []) {
                continue;
            }
            $sections[] = new Section(
                $topic,
                $state[$topic]['selected'],
                $state[$topic]['diversity'],
                $perspectives[$topic],
            );
        }

        return new Edition($now, Mode::Compact, $sections);
    }

    /**
     * Full: every classified article of the window, newest first — a
     * finite list, deliberately without engagement mechanics.
     *
     * @param array<string, list<Article>> $byTopic
     */
    private function buildFull(array $byTopic, \DateTimeImmutable $now): Edition
    {
        $sections = [];
        foreach (Classifier::TOPIC_ORDER as $topic) {
            $articles = $byTopic[$topic] ?? [];
            if ($articles === []) {
                continue;
            }
            $diversity = new Diversity();
            foreach ($articles as $article) {
                $diversity->add($article->source);
            }
            $sections[] = new Section($topic, $articles, $diversity, $this->candidatePerspectives($articles));
        }

        return new Edition($now, Mode::Full, $sections);
    }

    /**
     * Classifies fresh, deduplicated items into topics, newest first —
     * the shared first stage of both modes, also used by the categories
     * overview page.
     *
     * @param list<Item> $items
     *
     * @return array<string, list<Article>>
     */
    public function classifyFresh(Registry $registry, array $items, \DateTimeImmutable $now): array
    {
        $byTopic = [];
        $seen = [];

        foreach ($items as $item) {
            if (isset($seen[$item->link])) {
                continue;
            }
            $source = $registry->get($item->sourceId);
            if ($source === null || !$source->edition) {
                continue;
            }
            // Specialists publish slowly; a 48 h window would leave their
            // topics permanently empty, so they get two weeks.
            $maxAge = ($source->specialistTopic() !== null
                ? self::SPECIALIST_MAX_ITEM_AGE_HOURS
                : self::MAX_ITEM_AGE_HOURS) * 3600;
            $age = $now->getTimestamp() - $item->published->getTimestamp();
            if ($age > $maxAge || $age < -3600) {
                continue;
            }
            $topic = $this->classifier->classify($item, $source);
            if ($topic === null) {
                continue;
            }
            $seen[$item->link] = true;
            $byTopic[$topic][] = new Article($item, $source, $topic);
        }

        foreach ($byTopic as &$articles) {
            usort($articles, fn (Article $a, Article $b) => $b->item->published <=> $a->item->published);
            $articles = $this->clusterer->cluster($articles);
        }

        return $byTopic;
    }

    /**
     * Looks up a single classified article by its link — how the account
     * routes resolve what a reader clicked, saved or reported. Cluster
     * members resolve too (their links are on the card), each as its own
     * article so reads attribute to the source actually read. Anything
     * not currently in the edition simply does not resolve, so no request
     * can invent an article.
     *
     * @param list<Item> $items
     */
    public function findFresh(
        Registry $registry,
        array $items,
        \DateTimeImmutable $now,
        string $link,
    ): ?Article {
        foreach ($this->classifyFresh($registry, $items, $now) as $articles) {
            foreach ($articles as $article) {
                foreach ($article->tellings() as $telling) {
                    if ($telling->item->link === $link) {
                        return $telling;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Perspectives with at least one fresh candidate telling for a topic —
     * what blind-spot reporting measures the selection against
     * (rating-system.md §5). Cluster members count: their perspective was
     * available even where their telling did not lead.
     *
     * @param list<Article> $candidates
     *
     * @return list<string>
     */
    private function candidatePerspectives(array $candidates): array
    {
        $perspectives = [];
        foreach ($candidates as $candidate) {
            foreach ($candidate->tellings() as $telling) {
                $perspectives[$telling->source->perspective] = true;
            }
        }

        return array_keys($perspectives);
    }

    /**
     * Picks the single best remaining candidate into the topic state:
     * highest diversity gain (locally and relative to the whole edition
     * so far), reliability breaks ties, fresher candidates win among
     * equals. At most one article per source per topic. An optional
     * predicate narrows the field (the blindspot pass); scoring within
     * the field is unchanged. Returns false when no source-unique
     * candidate is left.
     *
     * @param list<Article>                                                        $candidates newest first
     * @param array{selected: list<Article>, used: array<string, true>, diversity: Diversity} $state
     * @param null|callable(Article): bool                                         $eligible
     */
    private function selectOne(array $candidates, array &$state, Diversity $global, ?callable $eligible = null): bool
    {
        $bestIndex = -1;
        $bestScore = -1;
        foreach ($candidates as $index => $candidate) {
            if (isset($state['used'][$candidate->source->id])) {
                continue;
            }
            if ($eligible !== null && !$eligible($candidate)) {
                continue;
            }
            $score = ($state['diversity']->gain($candidate->source) + $global->gain($candidate->source)) * 10
                + $candidate->source->rating->reliability;
            if ($score > $bestScore) {
                $bestIndex = $index;
                $bestScore = $score;
            }
        }
        if ($bestIndex < 0) {
            return false;
        }

        $article = $candidates[$bestIndex];
        $state['selected'][] = $article;
        $state['used'][$article->source->id] = true;
        $state['diversity']->add($article->source);
        $global->add($article->source);

        return true;
    }

    /**
     * A predicate matching candidates that close one of the section's
     * currently reported blind spots, or null when it reports none.
     * Reuses Section's accounting so "reported" means exactly what the
     * edition page would show — including the under-two guard.
     *
     * @param array{selected: list<Article>, used: array<string, true>, diversity: Diversity} $state
     * @param list<string>                                                                    $candidatePerspectives
     *
     * @return null|callable(Article): bool
     */
    private function blindspotCloser(string $topic, array $state, array $candidatePerspectives): ?callable
    {
        $section = new Section($topic, $state['selected'], $state['diversity'], $candidatePerspectives);
        $sides = $section->missingEconomicSides();
        $missingPerspectives = $section->missingPerspectives();
        if ($sides === [] && $missingPerspectives === []) {
            return null;
        }

        return fn (Article $a): bool => (in_array('left', $sides, true) && Band::of($a->source->rating->economic)->value <= 1)
            || (in_array('right', $sides, true) && Band::of($a->source->rating->economic)->value >= 3)
            || in_array($a->source->perspective, $missingPerspectives, true);
    }
}
