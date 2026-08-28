<?php

declare(strict_types=1);

namespace Meridian\Edition;

use Meridian\Registry\Registry;
use Meridian\Spectrum\AxisGrid;
use Meridian\Spectrum\Band;
use Meridian\Spectrum\Diversity;

/** The selected articles of one topic with their balance report. */
final class Section
{
    /**
     * @param list<Article>     $articles
     * @param list<string>|null $candidatePerspectives perspectives that had at
     *                                                 least one fresh candidate for this topic before
     *                                                 selection — the "possible" that blind-spot
     *                                                 reporting measures against; null when unknown
     *                                                 (e.g. archived editions)
     */
    public function __construct(
        public readonly string $topic,
        public array $articles,
        public readonly Diversity $diversity,
        private readonly ?array $candidatePerspectives = null,
    ) {
    }

    /**
     * Distribution of this section's articles across economic bands,
     * for the coverage bar (band value 0..4 => article count).
     *
     * @return array<int, int>
     */
    public function economicDistribution(): array
    {
        $distribution = [0, 0, 0, 0, 0];
        foreach ($this->articles as $article) {
            ++$distribution[Band::of($article->source->rating->economic)->value];
        }

        return $distribution;
    }

    /**
     * This section's articles on the economic × cultural plane, for the
     * two-axis coverage grid. Counts primaries only — the same accounting
     * as the coverage bar and the diversity report.
     *
     * @return array<int, array<int, int>> [economic band][cultural band] => count
     */
    public function axisGrid(): array
    {
        return AxisGrid::count(array_map(fn (Article $a) => $a->source, $this->articles));
    }

    /**
     * Economic sides (left/right) with no voice in this section — the
     * edition's own blind spots, from the same accounting that drives
     * the coverage bar. Sections with fewer than two articles always
     * miss a side, so they report none: the signal would be noise.
     *
     * @return list<string> subset of ["left", "right"]
     */
    public function missingEconomicSides(): array
    {
        if (count($this->articles) < 2) {
            return [];
        }

        $d = $this->economicDistribution();
        $missing = [];
        if ($d[0] + $d[1] === 0) {
            $missing[] = 'left';
        }
        if ($d[3] + $d[4] === 0) {
            $missing[] = 'right';
        }

        return $missing;
    }

    /**
     * Perspectives that published on this topic in the window but are not
     * visible in the section — measured against the possible (the
     * candidates), never against the ideal: a perspective that published
     * nothing is not a blind spot of the edition. Cluster members count
     * as visible; their telling is on the card. The under-two guard from
     * missingEconomicSides() applies for the same reason.
     *
     * @return list<string> in Registry::PERSPECTIVES order
     */
    public function missingPerspectives(): array
    {
        if ($this->candidatePerspectives === null || count($this->articles) < 2) {
            return [];
        }

        $visible = [];
        foreach ($this->articles as $article) {
            foreach ($article->tellings() as $telling) {
                $visible[$telling->source->perspective] = true;
            }
        }

        return array_values(array_filter(
            Registry::PERSPECTIVES,
            fn (string $p) => in_array($p, $this->candidatePerspectives, true) && !isset($visible[$p]),
        ));
    }
}
