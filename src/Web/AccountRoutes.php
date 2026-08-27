<?php

declare(strict_types=1);

namespace Meridian\Web;

use Meridian\Account\ArticleRef;
use Meridian\Account\Preferences;
use Meridian\Account\Reports;
use Meridian\Account\Sessions;
use Meridian\Account\Store;
use Meridian\Account\Watchlist;
use Meridian\Auth\OidcClient;
use Meridian\Auth\OidcConfig;
use Meridian\Auth\PendingLogin;
use Meridian\Auth\PendingLogins;
use Meridian\Edition\Article;
use Meridian\Edition\Builder;
use Meridian\Edition\Classifier;
use Meridian\Feed\ItemCache;
use Meridian\Registry\Registry;
use Meridian\Spectrum\Balance;

/**
 * Everything that needs an account: the OIDC handshake, settings, the
 * reading balance, the watchlist and classification reports.
 *
 * Returns null for paths it does not own, so App can fall through to the
 * public pages. Every state-changing route is a POST carrying the
 * session's CSRF token.
 */
final class AccountRoutes
{
    private const BALANCE_PERIODS = [7, 30, 90];
    private const REPORTS_PER_DAY = 20;

    private readonly PendingLogins $pendingLogins;

    public function __construct(
        private readonly Store $store,
        private readonly Registry $registry,
        private readonly Builder $builder,
        private readonly ItemCache $cache,
        private readonly ?OidcConfig $oidc,
        private readonly string $cacheDir,
    ) {
        $this->pendingLogins = new PendingLogins($store->db);
    }

    public function handle(Request $request, View $view, Viewer $viewer): ?Response
    {
        $post = $request->isPost();

        return match (true) {
            $request->normalizedPath() === '/login' && !$post => $this->startLogin($request, $view, $viewer),
            $request->normalizedPath() === OidcConfig::CALLBACK_PATH && !$post => $this->completeLogin($request, $view),
            $request->normalizedPath() === '/logout' && $post => $this->logout($request, $view, $viewer),
            $request->normalizedPath() === '/account' => $post
                ? $this->savePreferences($request, $view, $viewer)
                : $this->showAccount($request, $view, $viewer),
            $request->normalizedPath() === '/account/export' && !$post => $this->export($request, $view, $viewer),
            $request->normalizedPath() === '/account/history' && $post => $this->clearHistory($request, $view, $viewer),
            $request->normalizedPath() === '/account/delete' && $post => $this->deleteAccount($request, $view, $viewer),
            $request->normalizedPath() === '/balance' && !$post => $this->showBalance($request, $view, $viewer),
            $request->normalizedPath() === '/watchlist' => $post
                ? $this->changeWatchlist($request, $view, $viewer)
                : $this->showWatchlist($request, $view, $viewer),
            $request->normalizedPath() === '/read' && $post => $this->recordRead($request, $viewer),
            $request->normalizedPath() === '/report' => $post
                ? $this->submitReport($request, $view, $viewer)
                : $this->showReportForm($request, $view, $viewer),
            default => null,
        };
    }

    // ── Sign-in ───────────────────────────────────────────────────────

    private function startLogin(Request $request, View $view, Viewer $viewer): Response
    {
        if ($this->oidc === null) {
            return $this->accountsDisabled($view);
        }
        if ($viewer->isSignedIn()) {
            return Response::redirect('/account');
        }

        $login = PendingLogin::create($request->query('return') ?? '/');

        try {
            $url = $this->client()->authorizationUrl($login, $this->oidc->redirectUri($request->origin()));
        } catch (\Throwable $error) {
            return $this->loginFailed($view, 'discovery', $error);
        }

        $this->pendingLogins->remember($login, new \DateTimeImmutable());

        return Response::redirect($url, 302);
    }

    private function completeLogin(Request $request, View $view): Response
    {
        if ($this->oidc === null) {
            return $this->accountsDisabled($view);
        }
        if ($request->query('error') !== null) {
            return $this->loginFailed($view, 'provider');
        }

        $now = new \DateTimeImmutable();
        $code = $request->query('code');
        $login = $this->pendingLogins->take($request->query('state') ?? '', $now);
        if ($code === null || $login === null) {
            return $this->loginFailed($view, 'state');
        }

        try {
            $identity = $this->client()->exchangeCode($code, $login, $this->oidc->redirectUri($request->origin()));
        } catch (\Throwable $error) {
            return $this->loginFailed($view, 'exchange', $error);
        }

        $user = $this->store->accounts->upsert(
            $identity->issuer,
            $identity->subject,
            $identity->email,
            $identity->name,
            $now,
        );
        $token = $this->store->sessions->start($user->id, $now);

        return Response::redirect($login->returnTo)->withCookie(new Cookie(
            name: Sessions::COOKIE,
            value: $token,
            expires: time() + Sessions::LIFETIME_DAYS * 86400,
            secure: $request->secure,
        ));
    }

    private function logout(Request $request, View $view, Viewer $viewer): Response
    {
        if (!$viewer->isSignedIn()) {
            return Response::redirect('/');
        }
        if (($denied = $this->denyWithoutCsrf($request, $view, $viewer)) !== null) {
            return $denied;
        }

        $this->store->sessions->destroy($request->cookie(Sessions::COOKIE) ?? '');

        return Response::redirect('/')->withCookie(Cookie::forget(Sessions::COOKIE));
    }

    // ── Account ───────────────────────────────────────────────────────

    private function showAccount(Request $request, View $view, Viewer $viewer): Response
    {
        if (($denied = $this->denyWithoutUser($request, $view, $viewer)) !== null) {
            return $denied;
        }

        $userId = $viewer->user->id;
        $now = new \DateTimeImmutable();

        return $view->render('account.html.twig', [
            'nav_active' => 'account',
            'user' => $viewer->user,
            'preferences' => $viewer->preferences,
            'topics' => Classifier::TOPIC_ORDER,
            'retention_choices' => Preferences::RETENTION_CHOICES,
            'read_total' => $this->store->reads->countSince($userId, $now->modify('-100 years')),
            'read_today' => $this->store->reads->countSince($userId, $now->setTime(0, 0)),
            'watchlist_count' => $this->store->watchlist->count($userId),
            'watchlist_max' => Watchlist::MAX_ENTRIES,
            'session_days' => Sessions::LIFETIME_DAYS,
            'provider_logout' => $this->providerLogoutUrl(),
            'saved' => $request->query('saved') !== null,
            'cleared' => $request->query('cleared') !== null,
        ]);
    }

    private function savePreferences(Request $request, View $view, Viewer $viewer): Response
    {
        if (($denied = $this->denyWithoutUser($request, $view, $viewer)) !== null) {
            return $denied;
        }
        if (($denied = $this->denyWithoutCsrf($request, $view, $viewer)) !== null) {
            return $denied;
        }

        $this->store->accounts->savePreferences($viewer->user->id, Preferences::fromInput($request->body));

        return Response::redirect('/account?saved=1');
    }

    private function clearHistory(Request $request, View $view, Viewer $viewer): Response
    {
        if (($denied = $this->denyWithoutUser($request, $view, $viewer)) !== null) {
            return $denied;
        }
        if (($denied = $this->denyWithoutCsrf($request, $view, $viewer)) !== null) {
            return $denied;
        }

        $this->store->reads->clear($viewer->user->id);

        return Response::redirect('/account?cleared=1');
    }

    private function deleteAccount(Request $request, View $view, Viewer $viewer): Response
    {
        if (($denied = $this->denyWithoutUser($request, $view, $viewer)) !== null) {
            return $denied;
        }
        if (($denied = $this->denyWithoutCsrf($request, $view, $viewer)) !== null) {
            return $denied;
        }
        if ($request->input('confirm') === null) {
            return Response::redirect('/account');
        }

        $this->store->sessions->destroy($request->cookie(Sessions::COOKIE) ?? '');
        $this->store->accounts->delete($viewer->user->id);

        return $view->render('message.html.twig', [
            'kicker' => $view->t('account.deleted_kicker'),
            'title' => $view->t('account.deleted_title'),
            'text' => $view->t('account.deleted_text'),
        ])->withCookie(Cookie::forget(Sessions::COOKIE));
    }

    private function export(Request $request, View $view, Viewer $viewer): Response
    {
        if (($denied = $this->denyWithoutUser($request, $view, $viewer)) !== null) {
            return $denied;
        }

        return Response::jsonDownload(
            $this->store->accounts->export($viewer->user),
            'meridian-export.json',
        );
    }

    // ── Reading balance ───────────────────────────────────────────────

    private function showBalance(Request $request, View $view, Viewer $viewer): Response
    {
        if (($denied = $this->denyWithoutUser($request, $view, $viewer)) !== null) {
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

    private function showWatchlist(Request $request, View $view, Viewer $viewer): Response
    {
        if (($denied = $this->denyWithoutUser($request, $view, $viewer)) !== null) {
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
        if (($denied = $this->denyWithoutUser($request, $view, $viewer)) !== null) {
            return $denied;
        }
        if (($denied = $this->denyWithoutCsrf($request, $view, $viewer)) !== null) {
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

    private function showReportForm(Request $request, View $view, Viewer $viewer): Response
    {
        if (($denied = $this->denyWithoutUser($request, $view, $viewer)) !== null) {
            return $denied;
        }

        $article = $this->findArticle($request->query('url') ?? '');
        if ($article === null) {
            return $this->message($view, 'report.gone_kicker', 'report.gone_title', 'report.gone_text', 404);
        }

        return $view->render('report.html.twig', [
            'nav_active' => null,
            'article' => $article,
            'kinds' => Reports::KINDS,
        ]);
    }

    private function submitReport(Request $request, View $view, Viewer $viewer): Response
    {
        if (($denied = $this->denyWithoutUser($request, $view, $viewer)) !== null) {
            return $denied;
        }
        if (($denied = $this->denyWithoutCsrf($request, $view, $viewer)) !== null) {
            return $denied;
        }

        $now = new \DateTimeImmutable();
        if ($this->store->reports->countToday($viewer->user->id, $now) >= self::REPORTS_PER_DAY) {
            return $this->message($view, 'report.limit_kicker', 'report.limit_title', 'report.limit_text', 429);
        }

        $article = $this->findArticle($request->input('url') ?? '');
        if ($article === null) {
            return $this->message($view, 'report.gone_kicker', 'report.gone_title', 'report.gone_text', 404);
        }

        $this->store->reports->submit(
            $viewer->user->id,
            $article,
            $request->input('kind') ?? 'other',
            $request->input('note') ?? '',
            $now,
        );

        return $this->message($view, 'report.thanks_kicker', 'report.thanks_title', 'report.thanks_text');
    }

    // ── Shared plumbing ───────────────────────────────────────────────

    private function client(): OidcClient
    {
        if ($this->oidc === null) {
            throw new \LogicException('no identity provider configured');
        }

        return new OidcClient($this->oidc, $this->cacheDir);
    }

    private function providerLogoutUrl(): ?string
    {
        try {
            return $this->client()->endSessionUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    private function findArticle(string $url): ?Article
    {
        if ($url === '') {
            return null;
        }

        try {
            $items = $this->cache->load();
        } catch (\RuntimeException) {
            return null;
        }

        return $this->builder->findFresh($this->registry, $items, new \DateTimeImmutable(), $url);
    }

    private function denyWithoutUser(Request $request, View $view, Viewer $viewer): ?Response
    {
        if ($viewer->isSignedIn()) {
            return null;
        }
        if ($this->oidc === null) {
            return $this->accountsDisabled($view);
        }

        return Response::redirect('/login?return=' . rawurlencode($request->normalizedPath()));
    }

    private function denyWithoutCsrf(Request $request, View $view, Viewer $viewer): ?Response
    {
        if ($viewer->session?->verifyCsrf($request->input('_csrf')) === true) {
            return null;
        }

        return $this->message($view, 'auth.csrf_kicker', 'auth.csrf_title', 'auth.csrf_text', 400);
    }

    private function accountsDisabled(View $view): Response
    {
        return $this->message($view, 'auth.off_kicker', 'auth.off_title', 'auth.off_text', 404);
    }

    private function loginFailed(View $view, string $stage, ?\Throwable $error = null): Response
    {
        error_log(sprintf(
            'meridian: login failed at %s stage: %s',
            $stage,
            $error?->getMessage() ?? 'provider returned an error',
        ));

        return $this->message($view, 'auth.failed_kicker', 'auth.failed_title', 'auth.failed_text', 400);
    }

    private function message(View $view, string $kicker, string $title, string $text, int $status = 200): Response
    {
        return $view->render('message.html.twig', [
            'kicker' => $view->t($kicker),
            'title' => $view->t($title),
            'text' => $view->t($text),
        ], $status);
    }
}
