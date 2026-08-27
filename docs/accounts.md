# Accounts — technical specification

Status: implemented. This document is a contract: behaviour described
here and the code in `src/Account/`, `src/Auth/` and `src/Web/` must be
changed together.

## 1. Purpose and non-goals

Accounts exist for the things that cannot work without an identity across
devices:

1. **Reading balance** (`/balance`) — the product rates its sources; with
   an account it can hold the same mirror up to the reader.
2. **Cross-device read state** — the "already read" marker and the soft
   daily limit only mean something if they survive a device switch.
3. **Settings that follow the reader** — locale, default mode, muted
   topics, retention.
4. **Watchlist** — a small, finite "read later" pile.
5. **Classification reports** — a low-friction path for the contradiction
   the dataset is designed to invite (METHODOLOGY.md), with a usable
   abuse brake.

Explicit non-goals, because a login is the usual doorway to exactly the
mechanics this product exists to avoid: no comments, no likes, no share
counts, no push notifications, no personalised ranking, no streaks, no
reading goals, no recommendations.

## 2. Accounts are optional

The anonymous daily edition is the baseline, not a degraded mode.

- No identity provider configured → `OidcConfig::load()` returns `null`,
  no sign-in link is rendered, and account routes answer with the
  "this installation runs without accounts" page (HTTP 404).
- No account store file → no SQLite database is ever created. The
  connection in `Account\Database` is opened lazily for this reason.
- Signed out → every public page behaves exactly as before.

## 3. Sign-in

Authorization Code Flow with PKCE (S256). Meridian is the relying party;
it stores no password.

1. `GET /login` creates a `PendingLogin` (state, nonce, code verifier,
   return target) and stores it server-side in `auth_flows`. The
   authorization URL carries `state`, `nonce`, `code_challenge` and
   `code_challenge_method=S256`.
2. The reader authenticates at the provider and returns to
   `GET /auth/callback`.
3. The `state` is redeemed from the store **once** — reading it deletes
   it, so an intercepted callback URL cannot be replayed. Entries older
   than 15 minutes are not accepted.
4. The code is exchanged at the token endpoint over a direct TLS
   connection, authenticating with `client_secret_basic` when the
   provider advertises it, otherwise `client_secret_post`, otherwise as a
   public client (PKCE only).
5. The ID token must pass **all** of: signature against the provider's
   JWKS (RS256, 60 s clock skew), `iss` equal to the discovered issuer,
   `aud` containing the client ID, `exp`/`iat` valid, and `nonce` equal to
   the one from this handshake. Any failure aborts the sign-in; the
   reason is logged, never shown.
6. The reader is identified by the `(issuer, subject)` pair. Mail
   addresses change and are never the key.

Discovery metadata is cached for 24 h, JWKS for 6 h, both on disk in
`data/cache/`. A verification failure retries once against a freshly
fetched key set to survive key rotation.

Return targets are validated with `PendingLogin::safeReturnTo()`: only
same-site paths survive, so the login cannot be turned into an open
redirect.

**Sign-out** destroys the local session only. The provider session is not
ended — the account page offers the provider's own `end_session_endpoint`
as a separate, clearly labelled link when one is published.

## 4. Sessions and CSRF

- The cookie (`meridian-session`) carries 32 random bytes, base64url.
  Only its SHA-256 hash is stored, so a leaked database cannot be replayed
  as a login.
- Cookie flags: `HttpOnly`, `SameSite=Lax`, `Secure` whenever the request
  arrived over HTTPS. Lifetime 30 days, enforced server-side too.
- Every session carries its own CSRF token. **Every** state-changing
  route is a POST that must present it; `Session::verifyCsrf()` compares
  with `hash_equals`. This includes the read beacon.

## 5. Data model

One SQLite file, `data/accounts.sqlite`. Timestamps are UTC
`Y-m-d H:i:s` strings, which sort lexically and compare correctly in SQL.

| table         | holds                                                        |
| ------------- | ------------------------------------------------------------ |
| `users`       | issuer, subject, mail, display name, timestamps               |
| `preferences` | locale, default mode, muted topics, toggles, retention        |
| `sessions`    | token hash, CSRF token, expiry                                |
| `auth_flows`  | in-flight logins (single use, 15 min)                         |
| `reads`       | one row per opened article, unique per (user, URL hash)       |
| `watchlist`   | saved articles, unique per (user, URL hash), capped           |
| `reports`     | classification objections, `user_id` nulled on account delete |

Deleting a user cascades to preferences, sessions, reads and watchlist.
Reports survive **anonymised** — an objection already weighed against the
dataset should not disappear because its author left.

## 6. Article identity and forgery

The reading log, the watchlist and reports never trust what the client
sends beyond the link itself. Every write resolves the URL through
`Builder::findFresh()` against the current item cache and takes source
and topic from there. A link Meridian has not classified does not
resolve, so no request can invent reading history or point a report at
something that was never published.

Consequence by design: only what is currently inside the 48-hour window
can be saved, reported or recorded.

## 7. Preferences

| key              | values                              | default   |
| ---------------- | ----------------------------------- | --------- |
| `locale`         | `de`, `en`                          | `de`      |
| `mode`           | `compact`, `full`                   | `compact` |
| `muted_topics`   | subset of the focus topics          | none      |
| `daily_limit`    | on/off                              | on        |
| `track_reading`  | on/off                              | on        |
| `retention_days` | 30, 90, 365, 0 (keep until deleted) | 90        |

Locale resolution order: `?lang=` → account preference → cookie →
German. A signed-in reader switching language persists the change.
Reading mode: `?mode=` → account preference → compact.

**Muting is limited to topics.** There is deliberately no filter on
perspective, party family or spectrum position — see the invariant in
AGENTS.md. Muting every topic would empty the edition, so the last
remaining topic cannot be muted (`Preferences::fromInput()` drops the
final entry).

## 8. Reading state

An opened article is reported by `navigator.sendBeacon` to `POST /read`
(form fields `_csrf` and `url`). This is progressive enhancement: the
anchor keeps the real article URL, no link rewriting, no redirect hop.
Without JavaScript nothing is recorded and everything else still works.

Recording the same article twice keeps the first timestamp. Only the
fact that a link was opened is stored — no dwell time, no scroll depth,
no referrer.

The **soft daily limit** shows a notice once the reader has opened as
many articles today as a whole compact edition holds
(`Builder::MAX_ITEMS_TOTAL`). It is a notice, never a block.

## 9. Reading balance

`Spectrum\Balance` turns the reading log into the same kind of report
Meridian produces for its sources, over 7, 30 or 90 days.

- **Read share** — the reader's distribution across perspectives,
  economic bands, cultural bands and topics.
- **Offer share** — for perspective and the two spectrum axes, the
  composition of the source dataset. This is the reference that makes
  "a lot" and "a little" mean anything.
- **Topics carry no reference share.** The focus topics are not
  published in equal volume, so a percentage against the dataset would be
  misleading; what is informative there is a bucket at zero.
- **Blind spot** — a bucket with zero reads that the dataset does cover.
  A part of the spectrum Meridian has no source for is not a blind spot
  of the reader, and is not reported as one.
- **Lean** — a read share exceeding the offer share by at least 15
  percentage points (`Slice::NOTABLE_GAP`).
- Sources that have since left the dataset still count toward the total
  and topics, but not toward spectrum distributions or average
  reliability.

The report is diagnostic. It must not gain streaks, scores, goals or any
other mechanic that rewards reading *more* rather than *wider*.

## 10. Watchlist

Hard cap `Watchlist::MAX_ENTRIES` (30). Once full, adding fails and the
reader is told to clear something first; re-saving an already-saved
article still succeeds. Ordered oldest first — what has waited longest is
what the list is asking about. No pagination, no autoload.

## 11. Reports

Kinds: `topic`, `rating`, `source`, `other`, plus a free-text note capped
at 1000 characters. At most 20 per reader per day. Nothing a reader
submits changes the dataset by itself; review happens on the command
line:

```sh
bin/meridian reports:list
bin/meridian reports:list --resolve=<id>
```

## 12. Privacy

- Stored: OIDC issuer and subject, mail address and display name as sent
  by the provider, preferences, opened-article links, watchlist, reports.
- Not stored: passwords, IP addresses, user agents, dwell time, any
  third-party analytics. No data leaves the installation.
- `GET /account/export` returns everything about the reader as JSON.
- Account deletion is immediate and needs no request form.
- Retention is enforced by `bin/meridian accounts:prune`, which applies
  each reader's own setting and also clears expired sessions and stale
  login attempts. Run it daily next to `fetch`.

## 13. Configuration

Environment variables, or `config/oidc.php` returning the same keys (see
`config/oidc.php.example`); environment variables win.

| variable                      | required | meaning                                |
| ----------------------------- | -------- | -------------------------------------- |
| `MERIDIAN_OIDC_ISSUER`        | yes      | issuer URL, discovery is derived        |
| `MERIDIAN_OIDC_CLIENT_ID`     | yes      | registered client ID                    |
| `MERIDIAN_OIDC_CLIENT_SECRET` | no       | omit for a public PKCE-only client       |
| `MERIDIAN_OIDC_REDIRECT_URI`  | no       | defaults to `<origin>/auth/callback`     |
| `MERIDIAN_OIDC_SCOPES`        | no       | defaults to `openid profile email`       |

Issuer and client ID together are the switch: without both, Meridian runs
without accounts.
