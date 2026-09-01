<?php

declare(strict_types=1);

namespace Meridian\Web;

use Meridian\Auth\OidcConfig;

/**
 * Pages that are text rather than data: the methodology, the Impressum
 * and the Datenschutzerklärung. Returns null for paths it does not own.
 *
 * Legal canonical paths are /impressum (the concept is German law, the
 * document is German) and /privacy (universal); /datenschutz redirects
 * permanently. The footer links them from every page under exactly the
 * labels German practice expects ("Impressum", "Datenschutz").
 *
 * Draft guard: while a rendered legal document still contains a TODO
 * placeholder it is re-rendered with a visible draft banner — a legal
 * page with placeholders must never look final. The check runs on the
 * rendered output, so a TODO introduced anywhere in the template
 * reactivates the banner by itself.
 *
 * The accounts section of the privacy page names the identity provider
 * dynamically from the OIDC configuration, so the published text always
 * matches the deployment instead of a hardcoded name.
 */
final readonly class ContentPages
{
    public function __construct(private ?OidcConfig $oidc)
    {
    }

    public function handle(Request $request, View $view): ?Response
    {
        if ($request->isPost()) {
            return null;
        }

        return match ($request->normalizedPath()) {
            '/methodology' => $this->methodology($view),
            '/impressum' => $this->legal($view, 'legal/impressum.html.twig'),
            '/privacy' => $this->legal($view, 'legal/privacy.html.twig'),
            '/datenschutz' => Response::redirect('/privacy', 301),
            default => null,
        };
    }

    private function methodology(View $view): Response
    {
        $translator = $view->translator;

        return $view->render('methodology.html.twig', [
            'nav_active' => 'methodology',
            'why' => $translator->get('methodology.why'),
            'more' => $translator->get('methodology.more'),
            'selection' => $translator->get('methodology.selection'),
            'limits' => $translator->get('methodology.limits'),
            'glossary' => $translator->get('methodology.glossary'),
            'axes' => [
                ['key' => 'economic', 'title' => $translator->t('axis.economic_title'), 'bands' => $translator->get('axis.economic')],
                ['key' => 'cultural', 'title' => $translator->t('axis.cultural_title'), 'bands' => $translator->get('axis.cultural')],
                ['key' => 'eu', 'title' => $translator->t('axis.eu_title'), 'bands' => $translator->get('axis.eu')],
            ],
        ]);
    }

    private function legal(View $view, string $template): Response
    {
        $data = [
            'nav_active' => null,
            'oidc_issuer' => $this->oidc?->issuer,
        ];

        $page = $view->render($template, $data + ['is_draft' => false]);
        if (!str_contains($page->body, 'TODO')) {
            return $page;
        }

        return $view->render($template, $data + ['is_draft' => true]);
    }
}
