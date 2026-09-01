<?php

declare(strict_types=1);

namespace Meridian\Web\Twig;

use Meridian\Registry\Rating;
use Meridian\Spectrum\Labels;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** The display labels of the rating vocabulary, exposed as `label_*` functions. */
final class LabelExtension extends AbstractExtension
{
    public function __construct(private readonly Labels $labels)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('label_economic', $this->labels->economic(...)),
            new TwigFunction('label_cultural', $this->labels->cultural(...)),
            new TwigFunction('label_eu', $this->labels->euStance(...)),
            new TwigFunction('label_perspective', $this->labels->perspective(...)),
            new TwigFunction('label_party_family', $this->labels->partyFamily(...)),
            new TwigFunction('label_state_influence', $this->labels->stateInfluence(...)),
            new TwigFunction('label_topic', $this->labels->topic(...)),
            new TwigFunction('label_band', $this->labels->band(...)),
            new TwigFunction('label_slice', $this->labels->slice(...)),
            new TwigFunction('reliability_dots', Labels::reliabilityDots(...)),
            new TwigFunction('rating_summary', fn (Rating $r): string => $this->labels->summary($r)),
        ];
    }
}
