<?php

declare(strict_types=1);

namespace Meridian\Web;

/**
 * The checks in front of every account action. Each returns null when
 * the reader may proceed and the response that turns them away
 * otherwise, so a handler reads `if (($denied = …) !== null) return $denied;`.
 */
final class AccountGuard
{
    public static function requireUser(Request $request, View $view, Viewer $viewer): ?Response
    {
        if ($viewer->isSignedIn()) {
            return null;
        }
        if (!$viewer->accountsEnabled) {
            return self::disabled($view);
        }

        return Response::redirect('/login?return=' . rawurlencode($request->normalizedPath()));
    }

    public static function requireCsrf(Request $request, View $view, Viewer $viewer): ?Response
    {
        if ($viewer->session?->verifyCsrf($request->input('_csrf')) === true) {
            return null;
        }

        return $view->message('auth.csrf_kicker', 'auth.csrf_title', 'auth.csrf_text', 400);
    }

    /** An unconfigured identity provider is a normal state, not an error. */
    public static function disabled(View $view): Response
    {
        return $view->message('auth.off_kicker', 'auth.off_title', 'auth.off_text', 404);
    }
}
