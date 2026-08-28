# Rating System — Technical Specification

This document specifies *exactly* how Meridian's source rating system
works at code level: data model, value semantics, band boundaries,
validation rules, and how ratings drive edition building. The editorial
rationale (why these axes, how to research a rating) lives in
[METHODOLOGY.md](../METHODOLOGY.md); this file is the contract the code
implements. If code and this document disagree, one of them has a bug.

Relevant code:

| Concern | File |
|---|---|
| Data model | `src/Registry/Rating.php`, `src/Registry/Source.php` |
| Loading + validation | `src/Registry/Registry.php` |
| Band bucketing | `src/Spectrum/Band.php` |
| Display labels (locale-aware) | `src/Spectrum/Labels.php` + `translations/{de,en}.php` |
| Diversity accounting | `src/Spectrum/Diversity.php` |
| Two-axis coverage grid | `src/Spectrum/AxisGrid.php`, `templates/_axis_grid.html.twig` |
| Selection algorithm, story clustering | `src/Edition/Builder.php` |
| Tests | `tests/EditionTest.php` |

Display strings live exclusively in the message catalogs
(`translations/de.php`, `translations/en.php`); `Labels` resolves band
indices and enum keys against them, so adding a locale never touches
spectrum semantics.

## 1. Data model

Every source in `data/sources/*.yaml` carries a `rating` block:

| Field | Type | Range / values | Meaning |
|---|---|---|---|
| `economic` | float | −3.0 … +3.0 | redistributive left ↔ market-liberal right |
| `cultural` | float | −3.0 … +3.0 | GAL (green/alternative/libertarian) ↔ TAN (traditional/authoritarian/nationalist) |
| `eu_stance` | float | −3.0 … +3.0 | hard eurosceptic ↔ federalist |
| `party_family` | enum | `left`, `green`, `social-democratic`, `liberal`, `christian-democratic`, `conservative`, `national-conservative`, `right-populist`, `nonpartisan` | closest European party family |
| `reliability` | int | 1 … 5 | factual-reporting quality, independent of politics |
| `transparency` | enum | `low`, `medium`, `high` | ownership/funding disclosure |
| `state_influence` | enum | `none`, `indirect`, `state-affiliated`, `state-controlled` | see §4 |
| `confidence` | enum | `low`, `medium`, `high` | how solid this rating is |
| `evidence` | list | `{url, note}` entries | grounding for the rating |

Axis values are **judgements about the editorial line** (opinion sections,
framing choices), calibrated against Chapel Hill Expert Survey party
placements rescaled to −3…+3. They are *not* per-article measurements —
articles inherit their source's rating unchanged.

At source level, `wikidata` may hold the canonical QID of the outlet itself
(for example `Q161423`). It is optional because not every specialist outlet
has a verified Wikidata item. Code derives the entity URL from the QID; full
URLs and IDs for publishers or parent organisations are not valid values.

## 2. Band bucketing (exact boundaries)

`Band::of(float)` buckets every axis value into five bands. Boundaries
(note which side is inclusive):

| Band | Condition | Economic label | Cultural label | EU label |
|---|---|---|---|---|
| 0 Left | `v < −1.75` | links | progressiv (GAL) | EU-skeptisch |
| 1 CentreLeft | `−1.75 ≤ v < −0.5` | mitte-links | eher progressiv | eher EU-skeptisch |
| 2 Centre | `−0.5 ≤ v ≤ +0.5` | mitte | kulturell mittig | EU-neutral |
| 3 CentreRight | `+0.5 < v ≤ +1.75` | mitte-rechts | eher traditionell | pro-europäisch |
| 4 Right | `v > +1.75` | rechts | national-konservativ (TAN) | föderalistisch |

Consequences worth knowing:

- `−0.5` and `+0.5` are both **Centre**; `−1.75` is CentreLeft but
  `+1.75` is CentreRight (asymmetric because `<` vs `≤` — deliberate,
  centre band is closed on both sides).
- Bands drive three things: display labels, the dot colours in the UI
  gauges (`--econ-N` / `--cult-N` CSS variables, selected via the Twig
  `band_of()` function), and diversity accounting (§5).

## 3. Validation rules

`Registry::validate()` returns one message per violation. Enforced rules:

1. `id` non-empty; **duplicate IDs across all files are reported**
   (they would otherwise silently overwrite each other at load time —
   the constructor tracks duplicates for exactly this reason).
2. `name` non-empty.
3. `country` is a two-letter uppercase ISO code.
4. At least one feed URL.
5. `perspective` ∈ {dach, europe, global-south, international};
   `ownership`, `type`, `topics` against their enum lists
   (see constants in `src/Registry/Registry.php`).
6. All three axes within [−3, +3]; `reliability` within [1, 5].
7. `party_family`, `transparency`, `state_influence`, `confidence`
   against their enum lists.
8. When present, `wikidata` matches `Q[1-9][0-9]*` and is unique across
   sources. Validation is deliberately offline: curators verify that the
   item identifies the outlet before adding it.
9. `confidence` of `medium` or `high` **requires** at least one evidence
   entry; every evidence entry requires a non-empty `url` *and* a
   non-empty `note` (a bare link is not an explainable rating).

`bin/meridian sources:validate` is the CLI gate; the web app never
filters invalid entries itself.

## 4. Semantics that are easy to get wrong

- **`state_influence: indirect`** is for public-service broadcasters
  under pluralistic council governance (Deutschlandfunk). It is *not* a
  softer version of `state-affiliated` (state-funded without editorial
  independence guarantees — Al Jazeera) — they are different categories,
  and conflating them repeats the US-rater mistake this system exists to
  avoid.
- **`reliability` is orthogonal to the axes.** A source with a strong
  political line can be a 5; deductions come from fabrications, missing
  corrections policy, or documented systematic blind spots. A documented
  blind spot (e.g. a funder the outlet cannot report on) caps
  reliability at 3.
- **`perspective` is about the reporting network,** not the HQ country
  (Mongabay: US HQ, `global-south` perspective).
- **`nonpartisan` does not mean "centre".** It means the line does not
  track a party family; the axes still record any lean.

## 5. How ratings drive edition building

Ratings are used for *balance*, never for exclusion by politics.

### Diversity gain

`Diversity` tracks, per accumulator, which perspectives / economic bands /
cultural bands are already covered. Adding source *s* gains:

```
gain(s) = 4·[new perspective] + 2·[new economic band] + 1·[new cultural band]
```

Perspective dominates by design (the Global-South lens is the product's
reason to exist), then economic spread, then cultural spread.

### Candidate score

In compact mode, `Builder::selectOne()` repeatedly picks the candidate
with the highest score:

```
score(candidate) = (gain_local + gain_global) · 10 + reliability
```

- `gain_local`: relative to the topic section built so far.
- `gain_global`: relative to the whole edition built so far.
- The `·10` guarantees any diversity gain beats any reliability
  difference; reliability (1–5) only breaks ties.
- Among equal scores the **earlier candidate wins**, and candidate lists
  are sorted newest-first — so freshness is the final tie-breaker.
- At most **one article per source per topic** (`used` set). The rule
  binds cluster primaries; a source may additionally appear as a member
  of another story's cluster (§ story clustering below).

### Story clustering (phase 1: heuristic)

Within a topic, near-identical headlines from different sources are one
story: titles are compared as token sets against a cluster's primary,
Jaccard similarity ≥ 0.6 joins the cluster
(`Builder::clusterNearDuplicates`, matching rule in
docs/classification.md §1). The freshest telling is the **primary** and
is what selection, caps, diversity accounting and the coverage grid
count; the other tellings hang off the primary as `Article::alsoCoveredBy`
(one per source — a second telling from the same source is dropped) and
render as an "also covered by" line on the card, each with its economic
label. Cluster members resolve through `Builder::findFresh` like
primaries, each as its own article, so a read or report attributes to
the source actually read.

The caps therefore count *stories*, and one selected slot can carry
several perspectives — deliberately more perspective per attention
unit, never more scroll surface. Phase 2 (planned) swaps the heuristic
for similarity-based clustering behind the same interface; the
semantics above must not change with it.

### Fair topic quotas (compact mode)

Caps: `MAX_ITEMS_TOTAL = 12`, `MAX_ITEMS_PER_TOPIC = 4`. Since nine
topics × 4 = 36 > 12, naive in-order filling would permanently squeeze
out the later topics. Therefore:

1. **Pass 1 — base quota:** every non-empty topic gets
   `min(4, ⌊12 / non-empty topics⌋)` slots (9 non-empty → 1 each;
   4 → 3 each; 3 → 4 each).
2. **Pass 2 — round-robin top-up:** while total < 12, iterate topics in
   `TOPIC_ORDER` and add one more article where candidates remain and
   the per-topic cap (4) is not reached.

A topic that cannot fill its quota (few candidates) frees its slots for
the others via pass 2. Because pass 2 walks `TOPIC_ORDER`, the four
founding topics (climate, peace, digital-rights, accessibility) receive
surplus slots first — a deliberate property, not an accident of
iteration order.

### Full mode

No selection at all: every classified story of the freshness window,
newest first, grouped by topic. Ratings still appear on every card.

### Two-axis coverage grid

Each section head shows its articles on the economic × cultural band
plane as a 5×5 grid (`Section::axisGrid()` via `AxisGrid::count()`,
TAN up like the spectrum map) — the shape of coverage that two separate
bars cannot show, e.g. only the diagonal occupied. Cell intensity is
the article count; primaries only, the same accounting as
`Section::economicDistribution()` (which still feeds the accessible
summary). The balance page renders the same grid over a reader's
period, with the dataset as reference: cells the dataset offers but the
reader has not touched render dashed — a mirror, never a score. The
source directory (`/sources`) shows the grid once more over the whole
dataset, next to the spectrum map: the map places each outlet, the grid
shows which band combinations the dataset covers at all.

### Edition blind spots

A section names its own coverage gaps: when a section with at least two
articles has no voice from the economic left (bands 0–1) or right
(bands 3–4), the edition page says so
(`Section::missingEconomicSides()`). Sections with fewer than two
articles report nothing — a single article always "misses" a side, and
that signal would be noise. This is a mirror of the *edition*, the
counterpart to the balance page's mirror of the reader.

The same line names missing perspectives, measured **against the
possible, never against the ideal**: `Section::missingPerspectives()`
reports a perspective only when it had at least one fresh candidate
telling for the topic (`Builder::candidatePerspectives()`, cluster
members included) and still ends up invisible in the section. A
perspective that published nothing on the topic is not a blind spot of
the edition and is not reported. "Visible" also includes cluster
members — a telling on the card's "also covered by" line is a voice,
not a gap. The under-two guard applies as above; in full mode nothing
is ever missing because every candidate is shown. Sections without
candidate accounting (archived editions) report no perspective gaps.

## 6. Edition archive

`bin/meridian editions:archive` freezes the current compact edition
(anonymous default, no muted topics) into `data/archive/YYYY-MM-DD.json`
— intended for the daily cron, after `fetch`. Re-running a day
overwrites its snapshot (idempotent). Stored per article: source id and
name, title, link, summary, publication time, and the story cluster's
other tellings (`also_covered_by`: source id and name, title, link,
publication time each — see story clustering, §5); per section: the
topic.

`/archive` lists the archived days; `/archive/<date>` renders a frozen
edition **read-only** — no read tracking, watchlist or reports. Ratings
on the cards are resolved against the *current* registry at render time
and labelled as such; an article whose source has left the dataset
renders plainly with a note. The archive is a record, not a feed: it
loads nothing, counts nothing, and must never grow engagement
mechanics.

## 7. Change process

Changing a rating = changing `data/sources/*.yaml` + evidence, then
`bin/meridian sources:validate` and, if semantics changed, a matching
update to this document and METHODOLOGY.md. Ratings are contestable by
design; disagreements are resolved by better evidence, not by averaging.
