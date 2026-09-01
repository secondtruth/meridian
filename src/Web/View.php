<?php

declare(strict_types=1);

namespace Meridian\Web;

use Meridian\I18n\Translator;
use Meridian\Spectrum\Labels;
use Meridian\Web\Twig\FormatExtension;
use Meridian\Web\Twig\LabelExtension;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
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
        $this->twig->addExtension(new LabelExtension($this->labels));
        $this->twig->addExtension(new FormatExtension($translator, $request->now));
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

    /** A one-message page: every refusal, thank-you and farewell shares this shape. */
    public function message(string $kicker, string $title, string $text, int $status = 200): Response
    {
        return $this->render('message.html.twig', [
            'kicker' => $this->t($kicker),
            'title' => $this->t($title),
            'text' => $this->t($text),
        ], $status);
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

    /** URL of the current page with the language switched. */
    private function langUrl(string $locale): string
    {
        $query = array_merge($this->request->query, ['lang' => $locale]);

        return $this->request->normalizedPath() . '?' . http_build_query($query);
    }
}
