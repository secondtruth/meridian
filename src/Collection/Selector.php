<?php

declare(strict_types=1);

namespace Meridian\Collection;

use Meridian\Edition\Article;
use Meridian\Feed\Item;
use Meridian\Registry\Registry;

/**
 * Fills a collection from cached feed items: newest first within the
 * collection's window, capped per source and in total. Deliberately no
 * diversity scoring — collections are curated by their source list, and
 * the caps are the same finite-list discipline the edition has.
 */
final class Selector
{
    /**
     * @param list<Item> $items
     *
     * @return list<Article> articles carry the collection id as topic
     */
    public function select(
        Collection $collection,
        Registry $registry,
        array $items,
        \DateTimeImmutable $now,
    ): array {
        $members = array_fill_keys($collection->sources, true);
        $maxAge = $collection->windowDays * 86400;

        $fresh = [];
        $seen = [];
        foreach ($items as $item) {
            if (!isset($members[$item->sourceId]) || isset($seen[$item->link])) {
                continue;
            }
            $age = $now->getTimestamp() - $item->published->getTimestamp();
            if ($age > $maxAge || $age < -3600) {
                continue;
            }
            $seen[$item->link] = true;
            $fresh[] = $item;
        }
        usort($fresh, fn (Item $a, Item $b) => $b->published <=> $a->published);

        $selected = [];
        $perSource = [];
        foreach ($fresh as $item) {
            if (count($selected) === $collection->cap) {
                break;
            }
            $count = $perSource[$item->sourceId] ?? 0;
            if ($count === $collection->perSource) {
                continue;
            }
            $source = $registry->get($item->sourceId);
            if ($source === null) {
                continue;
            }
            $perSource[$item->sourceId] = $count + 1;
            $selected[] = new Article($item, $source, $collection->id);
        }

        return $selected;
    }
}
