<?php

declare(strict_types=1);

namespace Meridian\Web\Controller;

use Meridian\Account\Preferences;
use Meridian\Account\Sessions;
use Meridian\Account\Store;
use Meridian\Account\Watchlist;
use Meridian\Auth\OidcClient;
use Meridian\Edition\Classifier;
use Meridian\Web\Cookie;
use Meridian\Web\Request;
use Meridian\Web\Response;
use Meridian\Web\View;
use Meridian\Web\Viewer;

/**
 * The account itself: settings, export, clearing the history and
 * deleting the account. Every state-changing route is a POST carrying
 * the session's CSRF token. Returns null for paths it does not own.
 */
final readonly class AccountController
{
    public function __construct(
        private Store $store,
        private ?OidcClient $client,
    ) {
    }

    public function handle(Request $request, View $view, Viewer $viewer): ?Response
    {
        $post = $request->isPost();

        return match (true) {
            $request->normalizedPath() === '/account' => $post
                ? $this->savePreferences($request, $view, $viewer)
                : $this->show($request, $view, $viewer),
            $request->normalizedPath() === '/account/export' && !$post => $this->export($request, $view, $viewer),
            $request->normalizedPath() === '/account/history' && $post => $this->clearHistory($request, $view, $viewer),
            $request->normalizedPath() === '/account/delete' && $post => $this->delete($request, $view, $viewer),
            default => null,
        };
    }

    private function show(Request $request, View $view, Viewer $viewer): Response
    {
        if (($denied = AccountGuard::requireUser($request, $view, $viewer)) !== null) {
            return $denied;
        }

        $userId = $viewer->user->id;
        $now = $request->now;

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
        if (($denied = AccountGuard::requireUser($request, $view, $viewer)) !== null) {
            return $denied;
        }
        if (($denied = AccountGuard::requireCsrf($request, $view, $viewer)) !== null) {
            return $denied;
        }

        $this->store->accounts->savePreferences($viewer->user->id, Preferences::fromInput($request->body));

        return Response::redirect('/account?saved=1');
    }

    private function clearHistory(Request $request, View $view, Viewer $viewer): Response
    {
        if (($denied = AccountGuard::requireUser($request, $view, $viewer)) !== null) {
            return $denied;
        }
        if (($denied = AccountGuard::requireCsrf($request, $view, $viewer)) !== null) {
            return $denied;
        }

        $this->store->reads->clear($viewer->user->id);

        return Response::redirect('/account?cleared=1');
    }

    private function delete(Request $request, View $view, Viewer $viewer): Response
    {
        if (($denied = AccountGuard::requireUser($request, $view, $viewer)) !== null) {
            return $denied;
        }
        if (($denied = AccountGuard::requireCsrf($request, $view, $viewer)) !== null) {
            return $denied;
        }
        if ($request->input('confirm') === null) {
            return Response::redirect('/account');
        }

        $this->store->sessions->destroy($request->cookie(Sessions::COOKIE) ?? '');
        $this->store->accounts->delete($viewer->user->id);

        return $view->message('account.deleted_kicker', 'account.deleted_title', 'account.deleted_text')
            ->withCookie(Cookie::forget(Sessions::COOKIE));
    }

    private function export(Request $request, View $view, Viewer $viewer): Response
    {
        if (($denied = AccountGuard::requireUser($request, $view, $viewer)) !== null) {
            return $denied;
        }

        return Response::jsonDownload(
            $this->store->accounts->export($viewer->user),
            'meridian-export.json',
        );
    }

    /** The provider's own sign-out, offered next to Meridian's; unreachable metadata just hides the link. */
    private function providerLogoutUrl(): ?string
    {
        try {
            return $this->client?->endSessionUrl();
        } catch (\Throwable) {
            return null;
        }
    }
}
