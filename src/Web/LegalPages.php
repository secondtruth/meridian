<?php

declare(strict_types=1);

namespace Meridian\Web;

use Meridian\Auth\OidcConfig;

/**
 * Impressum and Datenschutzerklärung.
 *
 * Canonical paths are /impressum (the concept is German law, the document
 * is German) and /privacy (universal); /datenschutz redirects permanently.
 * The footer links them from every page under exactly the labels German
 * practice expects ("Impressum", "Datenschutz").
 *
 * Draft guard: while a rendered document still contains a TODO placeholder
 * it is re-rendered with a visible draft banner — a legal page with
 * placeholders must never look final. The check runs on the rendered
 * output, so a TODO introduced anywhere in the template reactivates the
 * banner by itself.
 *
 * The accounts section of the privacy page names the identity provider
 * dynamically from the OIDC configuration, so the published text always
 * matches the deployment instead of a hardcoded name.
 */
final readonly class LegalPages
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
            '/impressum' => $this->render($view, 'legal/impressum.html.twig'),
            '/privacy' => $this->render($view, 'legal/privacy.html.twig'),
            '/datenschutz' => Response::redirect('/privacy', 301),
            default => null,
        };
    }

    private function render(View $view, string $template): Response
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
