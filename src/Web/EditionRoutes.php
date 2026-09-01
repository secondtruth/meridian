<?php

declare(strict_types=1);

namespace Meridian\Web;

use Meridian\Account\Store;
use Meridian\Edition\Archive;
use Meridian\Edition\Builder;
use Meridian\Edition\Mode;
use Meridian\Feed\ItemCache;
use Meridian\Registry\Registry;

/**
 * Today's edition and its frozen predecessors: `/`, `/archive` and
 * `/archive/{date}`.
 *
 * Returns null for paths it does not own — including an archive date
 * that was never frozen, which App answers with its 404.
 */
final readonly class EditionRoutes
{
    private const WELCOME_COOKIE = 'meridian-welcome';

    public function __construct(
        private Registry $registry,
        private Builder $builder,
        private ItemCache $cache,
        private Archive $archive,
        private Store $store,
    ) {
    }

    public function handle(Request $request, View $view, Viewer $viewer): ?Response
    {
        if ($request->isPost()) {
            return null;
        }

        $path = $request->normalizedPath();
        if (preg_match('#\A/archive/(\d{4}-\d{2}-\d{2})\z#', $path, $m) === 1) {
            return $this->archiveDay($view, $m[1]);
        }

        return match ($path) {
            '/' => $this->edition($request, $view, $viewer),
            '/archive' => $this->archiveIndex($view),
            default => null,
        };
    }

    private function edition(Request $request, View $view, Viewer $viewer): Response
    {
        $mode = Mode::fromQuery($request->query('mode') ?? $viewer->preferences->mode->value);
        $now = new \DateTimeImmutable();
        $items = $this->cache->loadOrEmpty();
        $edition = $this->builder->build($this->registry, $items, $now, $mode, $viewer->preferences->mutedTopics);

        $links = [];
        foreach ($edition->sections as $section) {
            foreach ($section->articles as $article) {
                $links[] = $article->item->link;
            }
        }

        $read = [];
        $saved = [];
        $readToday = 0;
        if ($viewer->isSignedIn()) {
            $userId = $viewer->user->id;
            $read = $viewer->preferences->trackReading
                ? $this->store->reads->readAmong($userId, $links)
                : [];
            $saved = $this->store->watchlist->savedAmong($userId, $links);
            $readToday = $viewer->preferences->trackReading
                ? $this->store->reads->countSince($userId, $now->setTime(0, 0))
                : 0;
        }

        // First-visit welcome: shown until dismissed via /?welcome=off,
        // which persists a cookie — no account and no JavaScript needed.
        // Signed-in readers are past their first visit by definition.
        $welcomeOff = $request->query('welcome') === 'off';

        $response = $view->render('edition.html.twig', [
            'nav_active' => 'edition',
            'edition' => $edition,
            'mode' => $mode,
            'modes' => Mode::cases(),
            'date_human' => $view->localizedDate($now),
            'fetched_at' => $this->cache->lastFetchedAt(),
            'source_count' => count($edition->sourceIds()),
            'perspective_count' => count($edition->perspectives()),
            'max_total' => Builder::MAX_ITEMS_TOTAL,
            'window_hours' => Builder::MAX_ITEM_AGE_HOURS,
            'no_data' => $items === [],
            'read_urls' => $read,
            'saved_urls' => $saved,
            'read_today' => $readToday,
            'daily_limit_reached' => $viewer->preferences->dailyLimit
                && $readToday >= Builder::MAX_ITEMS_TOTAL,
            'muted_topics' => $viewer->preferences->mutedTopics,
            'show_welcome' => !$welcomeOff && !$viewer->isSignedIn()
                && $request->cookie(self::WELCOME_COOKIE) === null,
        ]);

        if ($welcomeOff) {
            $response = $response->withCookie(new Cookie(
                name: self::WELCOME_COOKIE,
                value: 'seen',
                expires: time() + 31536000,
                secure: $request->secure,
            ));
        }

        return $response;
    }

    private function archiveIndex(View $view): Response
    {
        $days = [];
        foreach ($this->archive->dates() as $date) {
            $days[] = [
                'date' => $date,
                'date_human' => $view->localizedDate(new \DateTimeImmutable($date)),
            ];
        }

        return $view->render('archive.html.twig', [
            'nav_active' => 'archive',
            'days' => $days,
        ]);
    }

    private function archiveDay(View $view, string $date): ?Response
    {
        $sections = $this->archive->restore($date, $this->registry);
        if ($sections === null) {
            return null;
        }

        return $view->render('archive_day.html.twig', [
            'nav_active' => 'archive',
            'date' => $date,
            'date_human' => $view->localizedDate(new \DateTimeImmutable($date)),
            'sections' => $sections,
        ]);
    }
}
