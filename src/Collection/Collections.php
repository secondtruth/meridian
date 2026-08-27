<?php

declare(strict_types=1);

namespace Meridian\Collection;

use Meridian\Registry\Registry;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads and validates data/collections.yaml — the curated collections
 * specified in docs/collections.md. Validation runs against the source
 * registry so a collection can never reference a source that does not
 * exist in the open dataset.
 */
final class Collections
{
    /** @var array<string, Collection> keyed by collection id, in file order */
    private array $collections = [];

    /** @var list<string> ids that appeared more than once while loading */
    private array $duplicateIds = [];

    /** @param list<Collection> $collections */
    public function __construct(array $collections)
    {
        foreach ($collections as $collection) {
            if (isset($this->collections[$collection->id])) {
                $this->duplicateIds[] = $collection->id;
            }
            $this->collections[$collection->id] = $collection;
        }
    }

    public static function load(string $file): self
    {
        $entries = Yaml::parseFile($file);
        if (!is_array($entries)) {
            throw new \RuntimeException("{$file}: expected a YAML list of collections");
        }

        return new self(array_map(Collection::fromArray(...), $entries));
    }

    /** @return list<Collection> */
    public function all(): array
    {
        return array_values($this->collections);
    }

    public function get(string $id): ?Collection
    {
        return $this->collections[$id] ?? null;
    }

    public function count(): int
    {
        return count($this->collections);
    }

    /** @return list<string> one message per violation, empty when valid */
    public function validate(Registry $registry): array
    {
        $problems = [];
        $report = function (string $id, string $message) use (&$problems): void {
            $problems[] = "{$id}: {$message}";
        };

        foreach ($this->duplicateIds as $id) {
            $report($id, 'duplicate id — a later entry silently replaced an earlier one');
        }

        foreach ($this->collections as $id => $c) {
            if ($id === '') {
                $problems[] = 'collection with empty id';
                continue;
            }
            if ($c->sources === []) {
                $report($id, 'no sources assigned');
            }
            foreach ($c->sources as $sourceId) {
                if ($registry->get($sourceId) === null) {
                    $report($id, "unknown source \"{$sourceId}\"");
                }
            }
            if (count($c->sources) !== count(array_unique($c->sources))) {
                $report($id, 'a source is listed more than once');
            }
            if ($c->windowDays < 1 || $c->windowDays > 31) {
                $report($id, "window_days out of range [1, 31]: {$c->windowDays}");
            }
            if ($c->cap < 1 || $c->cap > 24) {
                $report($id, "cap out of range [1, 24]: {$c->cap}");
            }
            if ($c->perSource < 1 || $c->perSource > $c->cap) {
                $report($id, "per_source out of range [1, cap]: {$c->perSource}");
            }
        }

        return $problems;
    }
}
