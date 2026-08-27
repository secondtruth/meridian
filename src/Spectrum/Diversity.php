<?php

declare(strict_types=1);

namespace Meridian\Spectrum;

use Meridian\Registry\Registry;
use Meridian\Registry\Source;

/** Tracks which parts of the spectrum a set of sources covers. */
final class Diversity
{
    /** @var array<int, true> */
    private array $economicBands = [];
    /** @var array<int, true> */
    private array $culturalBands = [];
    /** @var array<string, true> */
    private array $perspectives = [];

    /**
     * Scores how much adding the source would widen coverage. Perspectives
     * weigh most (the Global-South lens is the product's reason to exist),
     * then the economic axis, then the cultural axis. Zero: nothing new.
     */
    public function gain(Source $source): int
    {
        $gain = 0;
        if (!isset($this->perspectives[$source->perspective])) {
            $gain += 4;
        }
        if (!isset($this->economicBands[Band::of($source->rating->economic)->value])) {
            $gain += 2;
        }
        if (!isset($this->culturalBands[Band::of($source->rating->cultural)->value])) {
            $gain += 1;
        }

        return $gain;
    }

    public function add(Source $source): void
    {
        $this->perspectives[$source->perspective] = true;
        $this->economicBands[Band::of($source->rating->economic)->value] = true;
        $this->culturalBands[Band::of($source->rating->cultural)->value] = true;
    }

    /** @return list<string> covered perspective keys, in canonical order */
    public function coveredPerspectives(): array
    {
        $covered = [];
        foreach (Registry::PERSPECTIVES as $perspective) {
            if (isset($this->perspectives[$perspective])) {
                $covered[] = $perspective;
            }
        }

        return $covered;
    }
}
