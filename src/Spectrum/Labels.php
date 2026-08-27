<?php

declare(strict_types=1);

namespace Meridian\Spectrum;

use Meridian\I18n\Translator;
use Meridian\Registry\Rating;

/**
 * Locale-aware display labels for spectrum positions, backed by the
 * message catalogs. The GAL-TAN terminology follows European comparative
 * politics (Chapel Hill Expert Survey).
 */
final readonly class Labels
{
    public function __construct(private Translator $translator)
    {
    }

    public function economic(float $value): string
    {
        return $this->axis('economic', $value);
    }

    public function cultural(float $value): string
    {
        return $this->axis('cultural', $value);
    }

    public function euStance(float $value): string
    {
        return $this->axis('eu', $value);
    }

    public function partyFamily(string $family): string
    {
        return $this->translator->t("party_family.{$family}");
    }

    public function perspective(string $perspective): string
    {
        return $this->translator->t("perspective.{$perspective}");
    }

    public function stateInfluence(string $influence): string
    {
        return $this->translator->t("state_influence.{$influence}");
    }

    public function topic(string $topic): string
    {
        return $this->translator->t("topics.{$topic}.label");
    }

    /** One-line characterisation, e.g. "links · progressiv (GAL) · EU-neutral". */
    public function summary(Rating $rating): string
    {
        return $this->economic($rating->economic)
            . ' · ' . $this->cultural($rating->cultural)
            . ' · ' . $this->euStance($rating->euStance);
    }

    /** Reliability as filled/empty dots, e.g. "●●●●○". */
    public static function reliabilityDots(int $reliability): string
    {
        return str_repeat('●', $reliability) . str_repeat('○', 5 - $reliability);
    }

    /** The label of a band by its own value, e.g. for distribution charts. */
    public function band(string $axis, int $band): string
    {
        $labels = $this->translator->get("axis.{$axis}");

        return is_array($labels) ? (string) ($labels[$band] ?? $axis) : $axis;
    }

    private function axis(string $axis, float $value): string
    {
        return $this->band($axis, Band::of($value)->value);
    }
}
