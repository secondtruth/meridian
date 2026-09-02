<?php

declare(strict_types=1);

namespace Meridian\Web\Controller;

use Meridian\Account\Sessions;
use Meridian\Account\Store;
use Meridian\Auth\OidcClient;
use Meridian\Auth\OidcConfig;
use Meridian\Auth\PendingLogin;
use Meridian\Auth\PendingLogins;
use Meridian\Web\Cookie;
use Meridian\Web\Request;
use Meridian\Web\Response;
use Meridian\Web\View;
use Meridian\Web\Viewer;

/**
 * The OIDC handshake: `/login` starts it, the callback completes it,
 * `/logout` (POST, CSRF-checked) ends the session. A null client means
 * no identity provider is configured, which every route answers with
 * the same "accounts are off" page.
 */
final readonly class SignInController
{
    private PendingLogins $pendingLogins;

    public function __construct(
        private Store $store,
        private ?OidcClient $client,
    ) {
        $this->pendingLogins = new PendingLogins($store->db);
    }

    public function handle(Request $request, View $view, Viewer $viewer): ?Response
    {
        $post = $request->isPost();

        return match (true) {
            $request->normalizedPath() === '/login' && !$post => $this->start($request, $view, $viewer),
            $request->normalizedPath() === OidcConfig::CALLBACK_PATH && !$post => $this->complete($request, $view),
            $request->normalizedPath() === '/logout' && $post => $this->logout($request, $view, $viewer),
            default => null,
        };
    }

    private function start(Request $request, View $view, Viewer $viewer): Response
    {
        if ($this->client === null) {
            return AccountGuard::disabled($view);
        }
        if ($viewer->isSignedIn()) {
            return Response::redirect('/account');
        }

        $login = PendingLogin::create($request->query('return') ?? '/');

        try {
            $url = $this->client->authorizationUrl($login, $this->client->redirectUri($request->origin()));
        } catch (\Throwable $error) {
            return $this->failed($view, 'discovery', $error);
        }

        $this->pendingLogins->remember($login, $request->now);

        return Response::redirect($url, 302);
    }

    private function complete(Request $request, View $view): Response
    {
        if ($this->client === null) {
            return AccountGuard::disabled($view);
        }
        if ($request->query('error') !== null) {
            return $this->failed($view, 'provider');
        }

        $now = $request->now;
        $code = $request->query('code');
        $login = $this->pendingLogins->take($request->query('state') ?? '', $now);
        if ($code === null || $login === null) {
            return $this->failed($view, 'state');
        }

        try {
            $identity = $this->client->exchangeCode($code, $login, $this->client->redirectUri($request->origin()));
        } catch (\Throwable $error) {
            return $this->failed($view, 'exchange', $error);
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
        if (($denied = AccountGuard::requireCsrf($request, $view, $viewer)) !== null) {
            return $denied;
        }

        $this->store->sessions->destroy($request->cookie(Sessions::COOKIE) ?? '');

        return Response::redirect('/')->withCookie(Cookie::forget(Sessions::COOKIE));
    }

    private function failed(View $view, string $stage, ?\Throwable $error = null): Response
    {
        error_log(sprintf(
            'meridian: login failed at %s stage: %s',
            $stage,
            $error?->getMessage() ?? 'provider returned an error',
        ));

        return $view->message('auth.failed_kicker', 'auth.failed_title', 'auth.failed_text', 400);
    }
}
