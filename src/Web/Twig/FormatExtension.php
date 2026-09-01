<?php

declare(strict_types=1);

namespace Meridian\Web\Twig;

use Meridian\I18n\Translator;
use Meridian\Spectrum\Band;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Locale-aware formatting for templates: dates, decimals, relative
 * times, and the spectrum helpers that turn a rating into a position
 * or a colour token.
 */
final class FormatExtension extends AbstractExtension
{
    public function __construct(
        private readonly Translator $translator,
        private readonly \DateTimeImmutable $now,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('reltime', $this->relativeTime(...)),
            new TwigFilter('shortdate', $this->shortDate(...)),
            new TwigFilter('decimal', $this->decimal(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('axis_percent', fn (float $v): float => ($v + 3.0) / 6.0 * 100.0),
            new TwigFunction('band_of', fn (float $v): int => Band::of($v)->value),
            new TwigFunction('slice_color', self::sliceColor(...)),
        ];
    }

    public function shortDate(\DateTimeImmutable $date): string
    {
        return $date->format($this->translator->locale === 'de' ? 'd.m.Y' : 'Y-m-d');
    }

    public function decimal(float $value, int $precision = 1): string
    {
        return $this->translator->locale === 'de'
            ? number_format($value, $precision, ',', '.')
            : number_format($value, $precision, '.', ',');
    }

    public function relativeTime(\DateTimeImmutable $time): string
    {
        $minutes = intdiv($this->now->getTimestamp() - $time->getTimestamp(), 60);

        return match (true) {
            $minutes < 1 => $this->translator->t('reltime.now'),
            $minutes < 60 => $this->translator->t('reltime.minutes', $minutes),
            $minutes < 120 => $this->translator->t('reltime.hour'),
            $minutes < 48 * 60 => $this->translator->t('reltime.hours', intdiv($minutes, 60)),
            default => $this->shortDate($time),
        };
    }

    /** Keeps distribution bars on the same colour scale as the rest of the site. */
    public static function sliceColor(string $axis, string $key): string
    {
        return match ($axis) {
            'economic' => "var(--econ-{$key})",
            'cultural' => "var(--cult-{$key})",
            'perspective' => "var(--persp-{$key})",
            default => 'var(--accent)',
        };
    }
}
