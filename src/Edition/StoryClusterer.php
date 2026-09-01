<?php

declare(strict_types=1);

namespace Meridian\Edition;

/**
 * Collapses near-identical stories within a topic (same event covered
 * by several sources with barely different headlines) into one article
 * carrying the other tellings. Titles are compared as token sets
 * against the cluster's primary; Jaccard similarity ≥ 0.6 joins the
 * cluster. Lists are newest-first, so the freshest telling leads; a
 * second telling from an already-present source is dropped, never
 * attached (rating-system.md §5).
 */
final readonly class StoryClusterer
{
    public const SIMILARITY_THRESHOLD = 0.6;

    /**
     * @param list<Article> $articles newest first
     *
     * @return list<Article>
     */
    public function cluster(array $articles): array
    {
        /** @var list<array{article: Article, tokens: array<string, true>,
         *                  members: list<Article>, sources: array<string, true>}> $clusters */
        $clusters = [];

        foreach ($articles as $article) {
            $tokens = self::titleTokens($article->item->title);
            foreach ($clusters as &$cluster) {
                if (self::similarity($tokens, $cluster['tokens']) >= self::SIMILARITY_THRESHOLD) {
                    if (!isset($cluster['sources'][$article->source->id])) {
                        $cluster['members'][] = $article;
                        $cluster['sources'][$article->source->id] = true;
                    }
                    continue 2;
                }
            }
            unset($cluster);
            $clusters[] = [
                'article' => $article,
                'tokens' => $tokens,
                'members' => [],
                'sources' => [$article->source->id => true],
            ];
        }

        return array_map(
            fn (array $c) => $c['members'] === [] ? $c['article'] : new Article(
                $c['article']->item,
                $c['article']->source,
                $c['article']->topic,
                $c['members'],
            ),
            $clusters,
        );
    }

    /**
     * @param array<string, true> $a
     * @param array<string, true> $b
     */
    private static function similarity(array $a, array $b): float
    {
        $intersection = count(array_intersect_key($a, $b));
        $union = count($a) + count($b) - $intersection;

        return $union === 0 ? 0.0 : $intersection / $union;
    }

    /** @return array<string, true> lowercased word tokens of a headline */
    private static function titleTokens(string $title): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        return array_fill_keys(array_filter($words, fn (string $w) => mb_strlen($w) > 2), true);
    }
}
