<?php

declare(strict_types=1);

namespace Meridian\Web;

use Meridian\Account\ArticleRef;
use Meridian\Account\Reports;
use Meridian\Account\Store;
use Meridian\Account\Watchlist;
use Meridian\Auth\PendingLogin;
use Meridian\Edition\Article;
use Meridian\Edition\Builder;
use Meridian\Feed\ItemCache;
use Meridian\Registry\Registry;
use Meridian\Spectrum\Balance;

/**
 * What a signed-in reader does with articles: the reading balance, the
 * watchlist, the reading log and classification reports. Every article
 * resolves through {@see Builder::findFresh()} against the item cache,
 * so nothing a request sends can invent one (accounts.md). Returns null
 * for paths it does not own.
 */
final readonly class ReadingRoutes
{
    private const BALANCE_PERIODS = [7, 30, 90];
    private const REPORTS_PER_DAY = 20;

    public function __construct(
        private Store $store,
        private Registry $registry,
        private Builder $builder,
        private ItemCache $cache,
    ) {
    }

    public function handle(Request $request, View $view, Viewer $viewer): ?Response
    {
        $post = $request->isPost();

        return match (true) {
            $request->normalizedPath() === '/balance' && !$post => $this->balance($request, $view, $viewer),
            $request->normalizedPath() === '/watchlist' => $post
                ? $this->changeWatchlist($request, $view, $viewer)
                : $this->watchlist($request, $view, $viewer),
            $request->normalizedPath() === '/read' && $post => $this->recordRead($request, $viewer),
            $request->normalizedPath() === '/report' => $post
                ? $this->submitReport($request, $view, $viewer)
                : $this->reportForm($request, $view, $viewer),
            default => null,
        };
    }

    // ── Reading balance ───────────────────────────────────────────────

    private function balance(Request $request, View $view, Viewer $viewer): Response
    {
        if (($denied = AccountGuard::requireUser($request, $view, $viewer)) !== null) {
            return $denied;
        }

        $days = (int) ($request->query('days') ?? 30);
        $days = in_array($days, self::BALANCE_PERIODS, true) ? $days : 30;
        $now = new \DateTimeImmutable();
        $reads = $this->store->reads->since($viewer->user->id, $now->modify("-{$days} days"));

        return $view->render('balance.html.twig', [
            'nav_active' => 'balance',
            'report' => Balance::of($reads, $this->registry, $days),
            'days' => $days,
            'periods' => self::BALANCE_PERIODS,
            'tracking' => $viewer->preferences->trackReading,
            'source_count' => $this->registry->count(),
        ]);
    }

    // ── Watchlist ─────────────────────────────────────────────────────

    private function watchlist(Request $request, View $view, Viewer $viewer): Response
    {
        if (($denied = AccountGuard::requireUser($request, $view, $viewer)) !== null) {
            return $denied;
        }

        $entries = array_map(
            fn (ArticleRef $ref): array => ['ref' => $ref, 'source' => $this->registry->get($ref->sourceId)],
            $this->store->watchlist->all($viewer->user->id),
        );

        return $view->render('watchlist.html.twig', [
            'nav_active' => 'watchlist',
            'entries' => $entries,
            'max_entries' => Watchlist::MAX_ENTRIES,
            'full' => $request->query('full') !== null,
        ]);
    }

    private function changeWatchlist(Request $request, View $view, Viewer $viewer): Response
    {
        if (($denied = AccountGuard::requireUser($request, $view, $viewer)) !== null) {
            return $denied;
        }
        if (($denied = AccountGuard::requireCsrf($request, $view, $viewer)) !== null) {
            return $denied;
        }

        $url = $request->input('url') ?? '';
        $back = PendingLogin::safeReturnTo($request->input('return') ?? '/watchlist');

        if ($request->input('action') === 'remove') {
            $this->store->watchlist->remove($viewer->user->id, $url);

            return Response::redirect($back);
        }

        $article = $this->findArticle($url);
        if ($article === null) {
            return Response::redirect($back);
        }

        $added = $this->store->watchlist->add($viewer->user->id, $article, new \DateTimeImmutable());

        return Response::redirect($added ? $back : '/watchlist?full=1');
    }

    // ── Reading state ─────────────────────────────────────────────────

    /**
     * Records an opened article. The link is resolved against the item
     * cache, so only what Meridian itself classified can enter the log.
     */
    private function recordRead(Request $request, Viewer $viewer): Response
    {
        if (!$viewer->isSignedIn()
            || !$viewer->preferences->trackReading
            || !$viewer->session->verifyCsrf($request->input('_csrf'))) {
            return Response::noContent();
        }

        $article = $this->findArticle($request->input('url') ?? '');
        if ($article !== null) {
            $this->store->reads->record($viewer->user->id, $article, new \DateTimeImmutable());
        }

        return Response::noContent();
    }

    // ── Classification reports ────────────────────────────────────────

    private function reportForm(Request $request, View $view, Viewer $viewer): Response
    {
        if (($denied = AccountGuard::requireUser($request, $view, $viewer)) !== null) {
            return $denied;
        }

        // Source-anchored dissent (accounts.md §11): the "disagree?" entry
        // from a /sources card, no article involved.
        if (($sourceId = $request->query('source')) !== null) {
            $source = $this->registry->get($sourceId);
            if ($source === null) {
                return $view->message('report.gone_kicker', 'report.gone_source_title', 'report.gone_source_text', 404);
            }

            return $view->render('report.html.twig', [
                'nav_active' => null,
                'article' => null,
                'source' => $source,
                'kinds' => Reports::SOURCE_KINDS,
            ]);
        }

        $article = $this->findArticle($request->query('url') ?? '');
        if ($article === null) {
            return $view->message('report.gone_kicker', 'report.gone_title', 'report.gone_text', 404);
        }

        return $view->render('report.html.twig', [
            'nav_active' => null,
            'article' => $article,
            'source' => $article->source,
            'kinds' => Reports::KINDS,
        ]);
    }

    private function submitReport(Request $request, View $view, Viewer $viewer): Response
    {
        if (($denied = AccountGuard::requireUser($request, $view, $viewer)) !== null) {
            return $denied;
        }
        if (($denied = AccountGuard::requireCsrf($request, $view, $viewer)) !== null) {
            return $denied;
        }

        $now = new \DateTimeImmutable();
        if ($this->store->reports->countToday($viewer->user->id, $now) >= self::REPORTS_PER_DAY) {
            return $view->message('report.limit_kicker', 'report.limit_title', 'report.limit_text', 429);
        }

        if (($sourceId = $request->input('source')) !== null) {
            $source = $this->registry->get($sourceId);
            if ($source === null) {
                return $view->message('report.gone_kicker', 'report.gone_source_title', 'report.gone_source_text', 404);
            }
            $this->store->reports->submitForSource(
                $viewer->user->id,
                $source,
                $request->input('kind') ?? 'other',
                $request->input('note') ?? '',
                $now,
            );

            return $view->message('report.thanks_kicker', 'report.thanks_title', 'report.thanks_text');
        }

        $article = $this->findArticle($request->input('url') ?? '');
        if ($article === null) {
            return $view->message('report.gone_kicker', 'report.gone_title', 'report.gone_text', 404);
        }

        $this->store->reports->submit(
            $viewer->user->id,
            $article,
            $request->input('kind') ?? 'other',
            $request->input('note') ?? '',
            $now,
        );

        return $view->message('report.thanks_kicker', 'report.thanks_title', 'report.thanks_text');
    }

    private function findArticle(string $url): ?Article
    {
        if ($url === '') {
            return null;
        }

        return $this->builder->findFresh($this->registry, $this->cache->loadOrEmpty(), new \DateTimeImmutable(), $url);
    }
}
