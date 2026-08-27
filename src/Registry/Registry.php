<?php

declare(strict_types=1);

namespace Meridian\Registry;

use Symfony\Component\Yaml\Yaml;

/** Loads and validates the open dataset of media source classifications. */
final class Registry
{
    public const PARTY_FAMILIES = [
        'left', 'green', 'social-democratic', 'liberal',
        'christian-democratic', 'conservative', 'national-conservative',
        'right-populist', 'nonpartisan',
    ];
    public const PERSPECTIVES = ['dach', 'europe', 'global-south', 'international'];
    public const TOPICS = [
        'climate', 'peace', 'digital-rights', 'accessibility',
        'health', 'economy', 'democracy', 'migration', 'science',
        'general',
    ];
    public const OWNERSHIPS = ['public-service', 'cooperative', 'foundation', 'private', 'community', 'state'];
    public const SOURCE_TYPES = ['newspaper', 'magazine', 'public-broadcaster', 'online', 'agency', 'ngo-media'];
    public const TRANSPARENCIES = ['low', 'medium', 'high'];
    public const STATE_INFLUENCES = ['none', 'indirect', 'state-affiliated', 'state-controlled'];
    public const CONFIDENCES = ['low', 'medium', 'high'];

    /** @var array<string, Source> keyed by source ID, sorted by ID */
    private array $sources = [];

    /** @var list<string> IDs that appeared more than once while loading */
    private array $duplicateIds = [];

    /** @param list<Source> $sources */
    public function __construct(array $sources)
    {
        foreach ($sources as $source) {
            if (isset($this->sources[$source->id])) {
                $this->duplicateIds[] = $source->id;
            }
            $this->sources[$source->id] = $source;
        }
        ksort($this->sources);
    }

    public static function load(string $dir): self
    {
        $files = glob($dir . '/*.yaml') ?: [];
        if ($files === []) {
            throw new \RuntimeException("no source files found in {$dir}");
        }

        $sources = [];
        foreach ($files as $file) {
            $entries = Yaml::parseFile($file);
            if (!is_array($entries)) {
                throw new \RuntimeException("{$file}: expected a YAML list of sources");
            }
            foreach ($entries as $entry) {
                $sources[] = Source::fromArray($entry);
            }
        }

        return new self($sources);
    }

    /** @return list<Source> */
    public function all(): array
    {
        return array_values($this->sources);
    }

    public function get(string $id): ?Source
    {
        return $this->sources[$id] ?? null;
    }

    public function count(): int
    {
        return count($this->sources);
    }

    /**
     * Groups the sources by their publisher / media house, so the product
     * can surface media ownership. Ordered by outlet count (concentration
     * first), then publisher name; outlets keep the registry's ID order.
     *
     * @return list<Publisher>
     */
    public function publishers(): array
    {
        $groups = [];
        foreach ($this->all() as $source) {
            $groups[$source->publisher][] = $source;
        }

        $publishers = [];
        foreach ($groups as $name => $outlets) {
            $publishers[] = new Publisher((string) $name, $outlets);
        }

        usort(
            $publishers,
            fn (Publisher $a, Publisher $b): int
                => ($b->outletCount() <=> $a->outletCount()) ?: strcasecmp($a->name, $b->name),
        );

        return $publishers;
    }

    /**
     * Checks every source against the dataset rules.
     *
     * @return list<string> one message per violation, empty when valid
     */
    public function validate(): array
    {
        $problems = [];
        $wikidataSources = [];
        $report = function (string $id, string $message) use (&$problems): void {
            $problems[] = "{$id}: {$message}";
        };

        foreach ($this->duplicateIds as $id) {
            $report($id, 'duplicate id — a later entry silently replaced an earlier one');
        }

        foreach ($this->sources as $s) {
            $id = $s->id;
            if ($id === '') {
                $problems[] = 'source with empty id';
                continue;
            }
            if ($s->name === '') {
                $report($id, 'missing name');
            }
            if (strlen($s->country) !== 2 || strtoupper($s->country) !== $s->country) {
                $report($id, "country must be a two-letter ISO code, got \"{$s->country}\"");
            }
            if ($s->feeds === []) {
                $report($id, 'no feeds configured');
            }
            if (!in_array($s->perspective, self::PERSPECTIVES, true)) {
                $report($id, "invalid perspective \"{$s->perspective}\"");
            }
            if (!in_array($s->ownership, self::OWNERSHIPS, true)) {
                $report($id, "invalid ownership \"{$s->ownership}\"");
            }
            if (trim($s->publisher) === '') {
                $report($id, 'missing publisher — name the media house behind the outlet');
            }
            if ($s->wikidata !== null) {
                if (preg_match('/\AQ[1-9][0-9]*\z/D', $s->wikidata) !== 1) {
                    $report($id, "wikidata must be a canonical QID such as Q123, got \"{$s->wikidata}\"");
                } elseif (isset($wikidataSources[$s->wikidata])) {
                    $report($id, "wikidata {$s->wikidata} is already assigned to {$wikidataSources[$s->wikidata]}");
                } else {
                    $wikidataSources[$s->wikidata] = $id;
                }
            }
            if (!in_array($s->type, self::SOURCE_TYPES, true)) {
                $report($id, "invalid type \"{$s->type}\"");
            }
            if ($s->topics === []) {
                $report($id, 'no topics assigned');
            }
            foreach ($s->topics as $topic) {
                if (!in_array($topic, self::TOPICS, true)) {
                    $report($id, "invalid topic \"{$topic}\"");
                }
            }

            $rt = $s->rating;
            foreach (['economic' => $rt->economic, 'cultural' => $rt->cultural, 'eu_stance' => $rt->euStance] as $axis => $value) {
                if ($value < -3.0 || $value > 3.0) {
                    $report($id, "rating.{$axis} out of range [-3, 3]: {$value}");
                }
            }
            if ($rt->reliability < 1 || $rt->reliability > 5) {
                $report($id, "rating.reliability out of range [1, 5]: {$rt->reliability}");
            }
            if (!in_array($rt->partyFamily, self::PARTY_FAMILIES, true)) {
                $report($id, "invalid rating.party_family \"{$rt->partyFamily}\"");
            }
            if (!in_array($rt->transparency, self::TRANSPARENCIES, true)) {
                $report($id, "invalid rating.transparency \"{$rt->transparency}\"");
            }
            if (!in_array($rt->stateInfluence, self::STATE_INFLUENCES, true)) {
                $report($id, "invalid rating.state_influence \"{$rt->stateInfluence}\"");
            }
            if (!in_array($rt->confidence, self::CONFIDENCES, true)) {
                $report($id, "invalid rating.confidence \"{$rt->confidence}\"");
            }
            if ($rt->confidence !== 'low' && $rt->evidence === []) {
                $report($id, "rating.confidence is \"{$rt->confidence}\" but no evidence given");
            }
            foreach ($rt->evidence as $i => $entry) {
                if (!is_array($entry) || trim((string) ($entry['url'] ?? '')) === '') {
                    $report($id, "rating.evidence[{$i}] has no url");
                }
                if (trim((string) ($entry['note'] ?? '')) === '') {
                    $report($id, "rating.evidence[{$i}] has no note — evidence must be explainable");
                }
            }
        }

        return $problems;
    }
}
