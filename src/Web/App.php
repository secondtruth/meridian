<?php

declare(strict_types=1);

namespace Meridian\Web;

use Meridian\Account\Sessions;
use Meridian\I18n\Translator;
use Meridian\Services;

/**
 * Request-scoped wiring for all pages — Meridian is a website.
 *
 * App resolves who is reading and in which language, then hands the
 * request down a chain of route groups; each owns a set of paths and
 * returns null for the rest, so the first non-null response wins and
 * whatever nobody claims is a 404.
 */
final readonly class App
{
    private const LANG_COOKIE = 'meridian-lang';

    public function __construct(private Services $services)
    {
    }

    public function handle(Request $request): Response
    {
        $viewer = $this->resolveViewer($request);

        $requested = $request->query('lang');
        $locale = Translator::resolveLocale(
            $requested ?? ($viewer->isSignedIn()
                ? $viewer->preferences->locale
                : $request->cookie(self::LANG_COOKIE)),
        );
        if ($requested !== null && $viewer->isSignedIn() && $locale !== $viewer->preferences->locale) {
            $preferences = $viewer->preferences->withLocale($locale);
            $this->services->store()->accounts->savePreferences($viewer->user->id, $preferences);
            $viewer = $viewer->withPreferences($preferences);
        }

        $view = new View(
            $this->services->templatesDir(),
            $this->services->translator($locale),
            $request,
            $viewer,
            $this->mirroredFavicons(),
        );

        $response = $this->route($request, $view, $viewer);

        if ($requested !== null) {
            $response = $response->withCookie(new Cookie(
                name: self::LANG_COOKIE,
                value: $locale,
                expires: time() + 31536000,
                secure: $request->secure,
            ));
        }

        return $response;
    }

    private function route(Request $request, View $view, Viewer $viewer): Response
    {
        $s = $this->services;

        return new SignInRoutes($s->store(), $s->oidcClient())->handle($request, $view, $viewer)
            ?? new AccountRoutes($s->store(), $s->oidcClient())->handle($request, $view, $viewer)
            ?? new ReadingRoutes($s->store(), $s->registry(), $s->builder(), $s->itemCache())
                ->handle($request, $view, $viewer)
            ?? new ContentPages($s->oidcConfig())->handle($request, $view)
            ?? new EditionRoutes($s->registry(), $s->builder(), $s->itemCache(), $s->archive(), $s->store())
                ->handle($request, $view, $viewer)
            ?? new DatasetRoutes($s->registry(), $s->builder(), $s->itemCache(), $s->collections())
                ->handle($request, $view)
            ?? $view->render('notfound.html.twig', [], 404);
    }

    /**
     * Resolves who is reading. A missing account store, an unconfigured
     * provider or an unknown cookie all end up in the same place: an
     * anonymous reader with default preferences.
     */
    private function resolveViewer(Request $request): Viewer
    {
        $store = $this->services->store();
        $enabled = $this->services->oidcConfig() !== null;
        $token = $request->cookie(Sessions::COOKIE);
        if (!$enabled || $token === null || !$store->db->exists()) {
            return Viewer::anonymous($enabled);
        }

        $session = $store->sessions->lookup($token, $request->now);
        $user = $session === null ? null : $store->accounts->find($session->userId);
        if ($session === null || $user === null) {
            return Viewer::anonymous($enabled);
        }

        return new Viewer(
            preferences: $store->accounts->preferences($user->id),
            accountsEnabled: true,
            user: $user,
            session: $session,
        );
    }

    /**
     * Icons mirrored by `favicons:fetch` — self-hosted, so a card never
     * triggers a third-party request. An empty directory is the normal
     * state before the first cron run.
     *
     * @return array<string, string> source id => filename
     */
    private function mirroredFavicons(): array
    {
        $icons = [];
        foreach (glob($this->services->publicDir() . '/favicons/*.*') ?: [] as $path) {
            $filename = basename($path);
            $icons[pathinfo($filename, PATHINFO_FILENAME)] = $filename;
        }

        return $icons;
    }
}
