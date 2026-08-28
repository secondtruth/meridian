# Meridian 🧭

Europe-focused, bias-aware news aggregator with built-in doom-scrolling
protection. Meridians connect North and South — the product makes
Global-South perspectives systematically visible next to DACH and European
sources. Implements the Notion concept "News Aggregator (Europe-focused,
bias-aware)"; successor of the Go prototype `quellenkompass`.

## What it does

- Aggregates a **curated set of sources** (open dataset, `data/sources/`)
  classified on the **European political spectrum**: economic axis,
  GAL–TAN cultural axis, EU stance, party family, reliability, state
  influence, transparency — and linked to Wikidata where an outlet-level
  item is verifiable. See [METHODOLOGY.md](METHODOLOGY.md).
- Focuses on **nine topics**: climate, peace/anti-militarism, digital
  rights, accessibility, health, economy & labour, democracy & rule of
  law, migration, science — each defined as a curated selection of IPTC
  Media Topics subtrees (`data/topics.yaml`).
- Renders a server-side **daily edition** in two reading modes:
  - **Compact** (default, prioritised): hard cap of 12 articles, at most
    4 per topic, one per source per topic, perspective-balanced with fair
    per-topic quotas, near-duplicate headlines merged. No feed, no
    notifications, no infinite scroll.
  - **Full** (`/?mode=full`): every classified article of the last 48
    hours — still a finite list without engagement mechanics.
- **Overview pages**: `/sources` (all sources with a spectrum map,
  full classification details and evidence) and `/categories` (the
  topics with live counts, specialist sources and the active detection
  keywords).
- **Transparency page** `/methodology`: the rating model, the selection
  rules and a glossary of every term and abbreviation, as product copy.
- **Two languages**: German (default) and English via the `?lang=`
  switcher (cookie-persisted); all UI strings and spectrum labels come
  from message catalogs in `translations/`. Fonts are self-hosted.
- **Optional accounts** (OpenID Connect, off unless configured) for the
  few things that need an identity across devices — see
  [docs/accounts.md](docs/accounts.md):
  - **Reading balance** `/balance`: your own reading measured on the same
    axes as the sources, against how the dataset is composed — which
    perspectives and spectrum bands you never open. A mirror, not a
    score: no streaks, no goals, nothing that rewards reading *more*.
  - **Cross-device read state** with a soft daily-limit notice.
  - **Settings that follow you**: language, default mode, muted topics
    (topics only — never a perspective or spectrum filter), retention.
  - **Watchlist** `/watchlist`: capped at 30, oldest first, no autoload.
  - **Classification reports**: contradict a rating from the article,
    reviewed via `bin/meridian reports:list`.
  - Data export and immediate deletion on the account page.

## Stack

PHP (per the "Language & Software-Shape" heuristic: Meridian is
website-shaped — request-scoped rendering, cron-scale background work).
symfony/console for the CLI, symfony/yaml for the dataset, Twig for
templates, firebase/php-jwt for ID-token verification, PHPUnit for tests.
A JSON item cache decouples fetching from rendering; the editorial
product needs no database at all, and accounts add a single SQLite file
that is only created once an identity provider is configured.

## Usage

```sh
composer install

bin/meridian sources:list        # dataset with spectrum ratings
bin/meridian sources:validate    # dataset gate
bin/meridian fetch               # fetch feeds into data/cache/ (cron this daily)
bin/meridian accounts:prune      # expired sessions + retention (cron this daily)
bin/meridian reports:list        # reader objections to a classification

# dev server (router-script mode for /sources and /categories)
php -S 127.0.0.1:8932 -t public public/index.php
```

Accounts are off until an identity provider is configured — copy
`config/oidc.php.example` to `config/oidc.php` or set the
`MERIDIAN_OIDC_*` environment variables.

## How balance works

The edition builder classifies cached items into the focus topics
(language-aware keyword matching; specialist sources fall back to their
single topic), then greedily selects articles that widen coverage:
perspective diversity (DACH / Europe / Global South) weighs most, then
spread across economic-axis bands, then cultural bands; reliability breaks
ties. Ratings are never used to exclude by politics — every article shows
its source's full classification, and each topic section displays its
spectrum coverage bar.

## Repository layout

```
bin/meridian        CLI entry point (fetch, sources:*, accounts:prune, reports:list)
public/index.php    web front controller; public/fonts* self-hosted fonts
config/             optional OIDC settings (oidc.php.example)
src/Registry/       dataset types, loading, validation
src/Spectrum/       axis bands, locale-aware labels, diversity + reading balance
src/Feed/           RSS/Atom parsing, fetching, item cache
src/Edition/        classification, dedup, balanced selection, reading modes
src/I18n/           message-catalog translator
src/Web/            request/response, routing, Twig setup, account routes
src/Account/        optional accounts: store, preferences, reads, watchlist
src/Auth/           OpenID Connect relying party (PKCE, ID-token verification)
src/Support/        shared helpers (random tokens, JSON HTTP client)
templates/          layout + pages (editorial header, sober content area)
translations/       de.php / en.php message catalogs (all UI strings)
data/sources/       the open source-classification dataset (YAML)
docs/               technical specs: rating-system, classification, accounts
tests/              PHPUnit suite
```

## Documentation

- [METHODOLOGY.md](METHODOLOGY.md) — editorial rating methodology
- [docs/rating-system.md](docs/rating-system.md) — exact technical spec
  of the rating model, band boundaries, validation and selection math
- [docs/classification.md](docs/classification.md) — exact spec of
  category detection, matching strategies and keyword curation rules

## Self-hosting

Meridian needs PHP 8.4.1+ (`curl`, `simplexml`, and `pdo_sqlite` for the
optional accounts) and a web server pointed at `public/`. The shipped
[compose.yml](compose.yml) runs it as a single container:

```sh
docker compose run --rm composer install --no-dev --optimize-autoloader
docker compose up -d
docker compose exec app bin/meridian fetch
```

Four commands belong in a daily schedule — the edition is built from
whatever the cache holds, so nothing else is needed:

| Command | Purpose |
|---|---|
| `bin/meridian fetch` | pull all source feeds into the item cache |
| `bin/meridian favicons:fetch` | mirror outlet icons locally (no third-party requests) |
| `bin/meridian editions:archive` | freeze the day's edition for `/archive` |
| `bin/meridian accounts:prune` | expire sessions and reading history per retention |

Accounts are optional: without `config/oidc.php` every page works signed
out, which is the normal state, not an error (see
[docs/accounts.md](docs/accounts.md)).

## Status

Prototype. Known limitations are tracked in [TODO.md](TODO.md).

## License

[AGPL-3.0-or-later](LICENSE) — anyone may run, study and modify
Meridian, and anyone offering a modified instance as a service must
publish their changes. The IPTC Media Topics vocabulary referenced in
`data/topics.yaml` is © IPTC, [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).
