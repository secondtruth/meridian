<?php

declare(strict_types=1);

namespace Meridian\Web;

use Meridian\I18n\Translator;
use Meridian\Registry\Rating;
use Meridian\Spectrum\Band;
use Meridian\Spectrum\Labels;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** The template environment for one request, with its locale and viewer baked in. */
final class View
{
    public readonly Labels $labels;
    private readonly Environment $twig;

    /** @param array<string, string> $favicons source id => mirrored icon filename */
    public function __construct(
        string $templateDir,
        public readonly Translator $translator,
        private readonly Request $request,
        private readonly Viewer $viewer,
        array $favicons = [],
    ) {
        $this->labels = new Labels($translator);
        $this->twig = new Environment(new FilesystemLoader($templateDir), ['strict_variables' => true]);

        $this->twig->addGlobal('favicons', $favicons);
        $this->twig->addGlobal('locale', $translator->locale);
        $this->twig->addGlobal('other_locale', $translator->locale === 'de' ? 'en' : 'de');
        $this->twig->addGlobal('viewer', $viewer);
        $this->twig->addGlobal('current_path', $request->normalizedPath()
            . ($request->query === [] ? '' : '?' . http_build_query($request->query)));

        $this->twig->addFunction(new TwigFunction('t', $translator->t(...)));
        $this->twig->addFunction(new TwigFunction('lang_url', $this->langUrl(...)));
        $this->twig->addFunction(new TwigFunction('axis_percent', fn (float $v): float => ($v + 3.0) / 6.0 * 100.0));
        $this->twig->addFunction(new TwigFunction('band_of', fn (float $v): int => Band::of($v)->value));
        $this->twig->addFunction(new TwigFunction('label_economic', $this->labels->economic(...)));
        $this->twig->addFunction(new TwigFunction('label_cultural', $this->labels->cultural(...)));
        $this->twig->addFunction(new TwigFunction('label_eu', $this->labels->euStance(...)));
        $this->twig->addFunction(new TwigFunction('label_perspective', $this->labels->perspective(...)));
        $this->twig->addFunction(new TwigFunction('label_party_family', $this->labels->partyFamily(...)));
        $this->twig->addFunction(new TwigFunction('label_state_influence', $this->labels->stateInfluence(...)));
        $this->twig->addFunction(new TwigFunction('label_topic', $this->labels->topic(...)));
        $this->twig->addFunction(new TwigFunction('label_band', $this->labels->band(...)));
        $this->twig->addFunction(new TwigFunction('label_slice', $this->labelSlice(...)));
        $this->twig->addFunction(new TwigFunction('slice_color', $this->sliceColor(...)));
        $this->twig->addFunction(new TwigFunction('reliability_dots', Labels::reliabilityDots(...)));
        $this->twig->addFunction(new TwigFunction('rating_summary', fn (Rating $r): string => $this->labels->summary($r)));
        $this->twig->addFilter(new TwigFilter('reltime', $this->relativeTime(...)));
        $this->twig->addFilter(new TwigFilter('shortdate', $this->shortDate(...)));
        $this->twig->addFilter(new TwigFilter('decimal', $this->decimal(...)));
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->twig->render($template, $data + ['nav_active' => null]), $status);
    }

    public function t(string $key, int|float|string ...$args): string
    {
        return $this->translator->t($key, ...$args);
    }

    public function localizedDate(\DateTimeImmutable $date): string
    {
        $weekdays = $this->translator->get('date.weekdays');
        $months = $this->translator->get('date.months');

        return sprintf(
            $this->translator->t('date.pattern'),
            $weekdays[(int) $date->format('w')],
            (int) $date->format('j'),
            $months[(int) $date->format('n') - 1],
            (int) $date->format('Y'),
        );
    }

    /** Label of one distribution bucket, whichever dimension it belongs to. */
    private function labelSlice(string $axis, string $key): string
    {
        return match ($axis) {
            'perspective' => $this->labels->perspective($key),
            'topic' => $this->labels->topic($key),
            default => $this->labels->band($axis, (int) $key),
        };
    }

    /** Keeps distribution bars on the same colour scale as the rest of the site. */
    private function sliceColor(string $axis, string $key): string
    {
        return match ($axis) {
            'economic' => "var(--econ-{$key})",
            'cultural' => "var(--cult-{$key})",
            'perspective' => "var(--persp-{$key})",
            default => 'var(--accent)',
        };
    }

    /** URL of the current page with the language switched. */
    private function langUrl(string $locale): string
    {
        $query = array_merge($this->request->query, ['lang' => $locale]);

        return $this->request->normalizedPath() . '?' . http_build_query($query);
    }

    private function shortDate(\DateTimeImmutable $date): string
    {
        return $date->format($this->translator->locale === 'de' ? 'd.m.Y' : 'Y-m-d');
    }

    private function decimal(float $value, int $precision = 1): string
    {
        return $this->translator->locale === 'de'
            ? number_format($value, $precision, ',', '.')
            : number_format($value, $precision, '.', ',');
    }

    private function relativeTime(\DateTimeImmutable $time): string
    {
        $minutes = intdiv(time() - $time->getTimestamp(), 60);

        return match (true) {
            $minutes < 1 => $this->translator->t('reltime.now'),
            $minutes < 60 => $this->translator->t('reltime.minutes', $minutes),
            $minutes < 120 => $this->translator->t('reltime.hour'),
            $minutes < 48 * 60 => $this->translator->t('reltime.hours', intdiv($minutes, 60)),
            default => $time->format($this->translator->locale === 'de' ? 'd.m.Y' : 'Y-m-d'),
        };
    }
}
