<?php

declare(strict_types=1);

namespace Meridian\Registry;

/** One media outlet in the open classification dataset. */
final readonly class Source
{
    /**
     * @param list<string> $languages
     * @param list<string> $funding
     * @param list<string> $topics
     * @param list<string> $feeds
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $country,
        public array $languages,
        public string $type,
        public string $ownership,
        public string $publisher,
        public array $funding,
        public string $perspective,
        public array $topics,
        public string $homepage,
        public array $feeds,
        public Rating $rating,
        public ?string $wikidata = null,
        public bool $edition = true,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            country: (string) ($data['country'] ?? ''),
            languages: array_values($data['languages'] ?? []),
            type: (string) ($data['type'] ?? ''),
            ownership: (string) ($data['ownership'] ?? ''),
            publisher: (string) ($data['publisher'] ?? ''),
            funding: array_values($data['funding'] ?? []),
            perspective: (string) ($data['perspective'] ?? ''),
            topics: array_values($data['topics'] ?? []),
            homepage: (string) ($data['homepage'] ?? ''),
            feeds: array_values($data['feeds'] ?? []),
            rating: Rating::fromArray($data['rating'] ?? []),
            wikidata: array_key_exists('wikidata', $data) ? (string) $data['wikidata'] : null,
            edition: (bool) ($data['edition'] ?? true),
        );
    }

    public function wikidataUrl(): ?string
    {
        return $this->wikidata === null
            ? null
            : "https://www.wikidata.org/wiki/{$this->wikidata}";
    }

    public function publishesInGerman(): bool
    {
        return in_array('de', $this->languages, true);
    }

    /**
     * The single focus topic of a specialist source, or null for
     * generalists (sources listing "general" or several topics).
     */
    public function specialistTopic(): ?string
    {
        if (count($this->topics) !== 1 || $this->topics[0] === 'general') {
            return null;
        }

        return $this->topics[0];
    }
}
