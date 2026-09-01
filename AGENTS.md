# Meridian - Information for Coding Agents

## Project

Europe-focused, bias-aware news aggregator with doom-scrolling protection.
Concept lives in Notion ("News Aggregator (Europe-focused, bias-aware)",
fragment of the EcoFlame concept). Successor of the Go prototype
`../quellenkompass`. PHP was chosen per the
Notion "Language & Software-Shape (PHP vs. Go)" heuristic: the product is
website-shaped (request-scoped rendering; the daily fetch is cron-scale).

## Commands

```sh
composer install
vendor/bin/phpunit                  # tests
bin/meridian sources:validate       # dataset gate
bin/meridian fetch                  # feeds → data/cache/items.json
bin/meridian favicons:fetch         # mirror source favicons → public/favicons (cron, after fetch)
bin/meridian editions:archive       # freeze today's compact edition (daily cron, after fetch)
bin/meridian accounts:prune         # expired sessions + retention (daily cron)
bin/meridian reports:list           # reader objections to a classification
php -S 127.0.0.1:8932 -t public     # dev server
```

## Architecture

- `src/Registry` — YAML dataset loading + validation. One file per
  perspective group in `data/sources/`, each a YAML list of sources.
  `Source::wikidata` optionally links the exact outlet (never merely its
  publisher) by canonical QID; syntax and cross-source uniqueness are
  validated offline. `Topics` loads `data/topics.yaml`, the focus-topic
  vocabulary mapping every topic to IPTC Media Topics subtrees
  (`docs/classification.md` §6), validated by the same CLI gate.
- `src/Spectrum` — rating semantics: -3..+3 axes bucketed into five bands
  (`Band`), German labels (`Labels`), diversity accounting (`Diversity`:
  perspective > economic band > cultural band), the economic × cultural
  coverage grid (`AxisGrid`, rendered by `_axis_grid.html.twig`).
- `src/Feed` — hand-rolled RSS2/Atom parser (SimpleXML), curl fetcher with
  per-feed failure tolerance, JSON `ItemCache`; `FaviconMirror` stores
  source icons under `public/favicons/` so cards never make third-party
  requests.
- `src/Edition` — `Classifier` (language-aware matching, exactly
  specified in `docs/classification.md`), `Builder` with two `Mode`s:
  Compact (fair per-topic quotas + greedy diverse selection under hard
  caps, specified in `docs/rating-system.md` §5) and Full (everything
  classified, newest first). Near-identical headlines cluster into one
  story per `docs/rating-system.md` §5 (`Article::alsoCoveredBy`).
  `Archive` freezes each day's compact edition into `data/archive/`
  (`docs/rating-system.md` §6).
- `src/Collection` — curated collections (`data/collections.yaml`):
  explicit source lists with finite caps, selected newest-first, spec
  in `docs/collections.md`. Collection-only sources carry
  `edition: false` and stay out of the topic-classified edition. `Duel`
  is the first algorithmic collection (`docs/collections.md` §7): story
  clusters whose tellings sit ≥ 2 economic bands apart, headline
  against headline.
- `src/Spectrum/Balance` — the same accounting applied to a reader:
  `Distribution`/`Slice` (read share vs. the dataset's composition,
  blind spots, leans) → `BalanceReport` for `/balance`.
- `src/Web` — `App` wires services, resolves the viewer and locale, and
  hands the request down a chain of route groups; each owns a set of
  paths and returns null for the rest, the first non-null response wins,
  and what nobody claims is the 404. The groups: `AccountRoutes` (every
  path that needs an account), `ContentPages` (text rather than data:
  `/methodology`, `/impressum`, `/privacy`), `EditionRoutes` (`/`,
  `/archive`, `/archive/{date}` via `Archive::restore()`) and
  `DatasetRoutes` (`/sources`, `/publishers`, `/collections`,
  `/categories`). `/publishers` groups the sources by their `publisher`
  field (`Registry::publishers()` → `Registry\Publisher`) to surface
  media ownership, Ground-News-style. `SpectrumMap` lays out the
  `/sources` map (coincident dots spread apart, labels only where they
  collide with nothing, the rest numbered). `Request`/`Response`/`Cookie`
  keep handlers free of superglobals and `header()`; `View` is the
  per-request Twig environment, `Viewer` is who is reading (anonymous is
  a first-class case). `templates/layout.html.twig` holds the shared UI (editorial
  header, sober utilitarian content area, light/dark via `data-theme` +
  `prefers-color-scheme`), pages extend it.
- `src/Account` + `src/Auth` — optional accounts: OIDC authorization-code
  flow with PKCE, server-side sessions, preferences, reading log,
  watchlist, classification reports. `Account\Store` is the single handle
  over one lazily-opened SQLite file. **Read `docs/accounts.md` before
  touching either namespace** — it is a contract like the other specs.
- `src/I18n/Translator.php` + `translations/{de,en}.php` — all UI strings
  and display labels live in the catalogs (`t()` in Twig, `Labels` in
  PHP). Locale via `?lang=` + cookie, German default. **Never hardcode a
  user-facing string in a template or class** — add it to both catalogs.
- Fonts are self-hosted (`public/fonts/`, `public/fonts.css`) — do not
  reintroduce third-party font CDNs (GDPR).
- Dev server needs router-script mode:
  `php -S 127.0.0.1:8932 -t public public/index.php`.

## Design invariants (do not "improve" away)

- **Compact mode is the default and the product's priority.** Its hard
  caps (20 total / 4 per topic / 1 per source per topic) and the absence
  of feeds/notifications are the doom-scrolling protection. Full mode is
  a deliberate opt-in and must stay a finite list without engagement
  mechanics (no pagination-as-feed, no autoload).
- **Ratings never exclude by politics**; they inform balance and are
  displayed in full on every article. This holds for reader settings too:
  a reader may mute *topics*, never a perspective, party family or
  spectrum position. A "show me less right-wing coverage" switch is the
  exact filter bubble the product exists against — do not add it, however
  it is requested.
- **Accounts stay optional and add no engagement mechanics.** Every page
  must work signed out; an unconfigured identity provider is a normal
  state, not an error. What a login may buy is listed in
  `docs/accounts.md` §1 — no comments, likes, share counts, push
  notifications, personalised ranking, streaks or reading goals. The
  watchlist keeps its hard cap for the same reason the edition does.
- **The balance page is a mirror, not a score.** It must never reward
  reading more, only reveal where a reader has not looked.
- **Reading history is never taken from the client.** Reads, saves and
  reports resolve the link through `Builder::findFresh()` and take source
  and topic from the item cache, so nothing can be forged.
- **Rating changes require evidence** — the validator enforces evidence
  (with url *and* note) for medium/high confidence; read METHODOLOGY.md
  before touching `data/sources/`.
- **Keyword changes follow `docs/classification.md` §3** (compound-safety
  check, domain-specific inflections, regression test per fixed false
  positive).
- **Specs are contracts**: `docs/rating-system.md`,
  `docs/classification.md`, `docs/collections.md` and `docs/accounts.md`
  must be updated in the same change that alters the behaviour they
  describe.
- German product copy (web UI), English code/docs.

## Deployment

Server specifics (host, paths, deploy commands, cron) live in
`DEPLOY.local.md` on the working machine — git-ignored so the public
repository carries no infrastructure details. Portable rules: accounts
are disabled in production (account-gated UI must degrade to absent,
not broken), and the test suite plus `sources:validate` run before
every deploy.

## Task tracking

TODO.md holds active items, ideas and done log — keep it current.
