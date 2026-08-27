<?php

declare(strict_types=1);

namespace Meridian\Collection;

use Meridian\Edition\Article;
use Meridian\Spectrum\Band;

/**
 * The Perspective Duel: story clusters whose tellings sit at least two
 * economic bands apart, shown headline against headline so the reader
 * compares the framing themselves — no commentary, no winner
 * (collections.md §7). Algorithmic where the curated collections are
 * source-driven; the caps are the same finite-list discipline.
 */
final class Duel
{
    public const CAP = 6;

    /**
     * @param array<string, list<Article>> $byTopic clustered articles per topic
     *
     * @return list<array{story: Article, left: Article, right: Article}>
     *         newest first; left/right are the outermost tellings on the
     *         economic axis
     */
    public static function select(array $byTopic, int $cap = self::CAP): array
    {
        $rows = [];
        foreach ($byTopic as $articles) {
            foreach ($articles as $article) {
                $row = self::pair($article);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }
        usort($rows, fn (array $a, array $b) => $b['story']->item->published <=> $a['story']->item->published);

        return array_slice($rows, 0, $cap);
    }

    /** @return array{story: Article, left: Article, right: Article}|null */
    private static function pair(Article $article): ?array
    {
        $left = $right = $article;
        foreach ($article->tellings() as $telling) {
            if ($telling->source->rating->economic < $left->source->rating->economic) {
                $left = $telling;
            }
            if ($telling->source->rating->economic > $right->source->rating->economic) {
                $right = $telling;
            }
        }

        $span = Band::of($right->source->rating->economic)->value
            - Band::of($left->source->rating->economic)->value;

        return $span >= 2 ? ['story' => $article, 'left' => $left, 'right' => $right] : null;
    }
}
