<?php

declare(strict_types=1);

namespace Meridian\Registry;

/**
 * Positions a source in the European political spectrum.
 *
 * Unlike the US-centric left/right binary used by tools like Ground News,
 * this model follows the two-axis convention established by the Chapel Hill
 * Expert Survey (CHES) for European party systems, extended with dimensions
 * that matter specifically in the European media landscape (EU stance,
 * public-service vs. state media distinction). See METHODOLOGY.md.
 */
final readonly class Rating
{
    /**
     * @param float               $economic       -3 (redistributive left) .. +3 (market-liberal right)
     * @param float               $cultural       GAL-TAN: -3 (green/alternative/libertarian) .. +3 (traditional/authoritarian/nationalist)
     * @param float               $euStance       -3 (hard eurosceptic) .. +3 (federalist/pro-integration)
     * @param string              $partyFamily    closest European party family, or "nonpartisan"
     * @param int                 $reliability    factual reporting quality: 1 (poor) .. 5 (excellent)
     * @param string              $transparency   ownership/funding disclosure: low, medium, high
     * @param string              $stateInfluence none, indirect, state-affiliated, state-controlled
     * @param string              $confidence     confidence in this rating: low, medium, high
     * @param list<array{url: string, note: string}> $evidence
     */
    public function __construct(
        public float $economic,
        public float $cultural,
        public float $euStance,
        public string $partyFamily,
        public int $reliability,
        public string $transparency,
        public string $stateInfluence,
        public string $confidence,
        public array $evidence = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            economic: (float) ($data['economic'] ?? 0.0),
            cultural: (float) ($data['cultural'] ?? 0.0),
            euStance: (float) ($data['eu_stance'] ?? 0.0),
            partyFamily: (string) ($data['party_family'] ?? ''),
            reliability: (int) ($data['reliability'] ?? 0),
            transparency: (string) ($data['transparency'] ?? ''),
            stateInfluence: (string) ($data['state_influence'] ?? ''),
            confidence: (string) ($data['confidence'] ?? ''),
            evidence: array_values($data['evidence'] ?? []),
        );
    }
}
