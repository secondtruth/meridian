<?php

declare(strict_types=1);

namespace Meridian\Web;

use Meridian\Account\Sessions;
use Meridian\Account\Store;
use Meridian\Auth\OidcClient;
use Meridian\Auth\OidcConfig;
use Meridian\Edition\Archive;
use Meridian\Edition\Builder;
use Meridian\Feed\ItemCache;
use Meridian\I18n\Translator;
use Meridian\Registry\Registry;

/**
 * Request-scoped wiring for all pages — Meridian is a website.
 *
 * App resolves who is reading and in which language, then hands the
 * request down a chain of route groups; each owns a set of paths and
 * returns null for the rest, so the first non-null response wins and
 * whatever nobody claims is a 404.
 */
final class App
{
    private const LANG_COOKIE = 'meridian-lang';

    private readonly Registry $registry;
    private readonly ItemCache $cache;
    private readonly Builder $builder;
    private readonly Store $store;
    private readonly ?OidcConfig $oidc;

    public function __construct(private readonly string $rootDir)
    {
        $this->registry = Registry::load($rootDir . '/data/sources');
        $this->cache = new ItemCache($rootDir . '/data/cache/items.json');
        $this->builder = new Builder();
        $this->store = Store::at($rootDir);
        $this->oidc = OidcConfig::load($rootDir);
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
            $this->store->accounts->savePreferences($viewer->user->id, $preferences);
            $viewer = $viewer->withPreferences($preferences);
        }

        $view = new View(
            $this->rootDir . '/templates',
            new Translator($locale, $this->rootDir . '/translations'),
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
        $client = $this->oidc === null ? null : new OidcClient($this->oidc, $this->rootDir . '/data/cache');

        return new SignInRoutes($this->store, $client)->handle($request, $view, $viewer)
            ?? new AccountRoutes($this->store, $client)->handle($request, $view, $viewer)
            ?? new ReadingRoutes($this->store, $this->registry, $this->builder, $this->cache)
                ->handle($request, $view, $viewer)
            ?? new ContentPages($this->oidc)->handle($request, $view)
            ?? new EditionRoutes(
                $this->registry,
                $this->builder,
                $this->cache,
                new Archive($this->rootDir . '/data/archive'),
                $this->store,
            )->handle($request, $view, $viewer)
            ?? new DatasetRoutes(
                $this->registry,
                $this->builder,
                $this->cache,
                $this->rootDir . '/data/collections.yaml',
            )->handle($request, $view)
            ?? $view->render('notfound.html.twig', [], 404);
    }

    /**
     * Resolves who is reading. A missing account store, an unconfigured
     * provider or an unknown cookie all end up in the same place: an
     * anonymous reader with default preferences.
     */
    private function resolveViewer(Request $request): Viewer
    {
        $enabled = $this->oidc !== null;
        $token = $request->cookie(Sessions::COOKIE);
        if (!$enabled || $token === null || !$this->store->db->exists()) {
            return Viewer::anonymous($enabled);
        }

        $session = $this->store->sessions->lookup($token, new \DateTimeImmutable());
        $user = $session === null ? null : $this->store->accounts->find($session->userId);
        if ($session === null || $user === null) {
            return Viewer::anonymous($enabled);
        }

        return new Viewer(
            preferences: $this->store->accounts->preferences($user->id),
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
        foreach (glob($this->rootDir . '/public/favicons/*.*') ?: [] as $path) {
            $filename = basename($path);
            $icons[pathinfo($filename, PATHINFO_FILENAME)] = $filename;
        }

        return $icons;
    }
}
