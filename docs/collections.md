# Collections — Technical Specification

This document specifies the curated collections: cross-topic article
lists selected by explicit inclusion criteria instead of engagement
signals. If code and this document disagree, one of them has a bug.

Relevant code:

| Concern | File |
|---|---|
| Collection definitions | `data/collections.yaml` |
| Loading + validation | `src/Collection/Collections.php` |
| Selection | `src/Collection/Selector.php` |
| Perspective Duel (§7) | `src/Collection/Duel.php` |
| Edition exclusion flag | `src/Registry/Source.php` (`edition`), `src/Edition/Builder.php` (`classifyFresh`) |
| Page | `src/Web/App.php` (`renderCollections`), `templates/collections.html.twig` |
| Tests | `tests/CollectionsTest.php` |

## 1. What a collection is (and is not)

Collections are **orthogonal to the focus topics**: they group articles
by audience, genre or vantage point (Good News, For Children, Simple
Language, Global South Lens) — never by what a reader clicked. Every
collection states a human-readable **inclusion criterion**
(`collections.<id>.criteria` in both message catalogs) and an explicit
source list; membership is curated, not computed.

Collections inherit the edition's finite-list discipline: hard caps, no
feed mechanics, no ranking by reactions. They must never become a
recommendation surface.

## 2. Data model (`data/collections.yaml`)

| Field | Type | Range | Meaning |
|---|---|---|---|
| `id` | string | unique | kebab-case identifier, also the catalog key |
| `sources` | list | ≥ 1, no duplicates, every id in the registry | the curated member sources |
| `window_days` | int | 1 … 31 | freshness window (collections may breathe slower than the 48-hour edition) |
| `cap` | int | 1 … 24 | hard total cap |
| `per_source` | int | 1 … cap | hard per-source cap |

`bin/meridian sources:validate` enforces all of this against the loaded
registry.

## 3. Selection

Per collection: cached items from member sources, deduplicated by exact
link, within `window_days` (items dated more than 1 hour in the future
are dropped, mirroring the edition's broken-clock rule), sorted newest
first, then taken in order while the per-source and total caps allow.
Deliberately **no diversity scoring** — a collection is balanced by its
curated source list, not by the selection algorithm.

## 4. Collection-only sources (`edition: false`)

Sources that exist for collections (children's news, Einfache Sprache,
good-news curation) carry `edition: false` in `data/sources/*.yaml`:
they are fetched like every source and appear on the sources page with
full ratings, but `Builder::classifyFresh` skips them, so they never
enter the topic-classified edition — kids' news must not compete in the
peace section. The default is `edition: true`; the flag is not an
exclusion by politics (which remains forbidden) but a surface decision.

## 5. Page behaviour

`/collections` renders every collection with its criterion and its
articles as **read-only cards**: no read tracking, no watchlist or
report actions. Those actions resolve links through
`Builder::findFresh` (see docs/accounts.md), which only knows
edition-classified articles — rather than let the buttons fail
silently, the collection page does not offer them. The card partial
(`templates/_article_card.html.twig`) is shared with the edition and
takes an `interactive` flag.

## 6. Extending

- New collection: add the entry to `data/collections.yaml`, add
  `collections.<id>.label` and `.criteria` to **both** catalogs, run
  `bin/meridian sources:validate` and the test suite (the shipped-file
  test validates against the live registry).
- New collection-only source: a full dataset entry per METHODOLOGY.md
  (ratings, evidence) plus `edition: false`.
- An algorithmic collection is a different mechanism from the curated
  ones — specify it here first, do not bend `Selector` into it. The
  Perspective Duel (§7) is the first of this kind.

## 7. Perspective Duel (algorithmic)

The duel pairs the same story across spectrum bands so the reader
compares the framing themselves — the AllSides headline-roundup
pattern, built on the edition's story clusters
(docs/rating-system.md §5).

- **Input:** the topic-classified, clustered articles of the current
  edition window (`Builder::classifyFresh`) — the duel introduces no
  source list and no separate window of its own.
- **Qualification:** a story qualifies when its outermost tellings on
  the economic axis sit **at least two bands apart**
  (`Band::of(rightmost) − Band::of(leftmost) ≥ 2`). The two outermost
  tellings (by axis value, not band) are the duel pair; the card names
  how many sources cover the story in total.
- **Cap:** newest first, at most `Duel::CAP = 6` stories — the same
  finite-list discipline as everywhere.
- **Presentation:** the two headlines side by side on `/collections`,
  each with source and economic label, **no commentary and no winner**.
  Ratings inform the pairing; they exclude nothing.
- **Read-only** like the rest of the collections page (§5) — one rule
  per page, even though duel articles are edition-classified and would
  resolve.
- An empty duel renders as a statement, not as an error: no story with
  voices far enough apart is itself a finding.
