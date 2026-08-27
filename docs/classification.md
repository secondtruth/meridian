# Category Detection — Technical Specification

This document specifies exactly how Meridian assigns feed items to the
nine focus categories defined in `data/topics.yaml` (§6): climate,
peace, digital-rights, accessibility, health, economy, democracy,
migration and science. If code and this document disagree, one of them
has a bug.

Relevant code:

| Concern | File |
|---|---|
| Keyword lists + matching | `src/Edition/Classifier.php` |
| Pipeline (freshness, dedup, fallback) | `src/Edition/Builder.php` (`classifyFresh`) |
| Topic vocabulary (IPTC mapping) | `data/topics.yaml`, `src/Registry/Topics.php` |
| Tests incl. false-positive regressions | `tests/EditionTest.php`, `tests/TopicsTest.php` |

## 1. Pipeline

For every cached feed item, in order:

1. **Freshness filter.** Items older than 48 hours
   (`Builder::MAX_ITEM_AGE_HOURS`) are dropped, as are items dated more
   than 1 hour in the future (broken feed clocks). Items from
   **specialist sources** (exactly one non-`general` topic) get 336
   hours (`SPECIALIST_MAX_ITEM_AGE_HOURS`) — specialists publish
   slowly, and a 48 h window left their topics permanently empty.
2. **Deduplication and clustering** by exact link URL (first occurrence
   wins; candidate lists are later sorted newest-first). After
   classification, a second pass per topic clusters **near-identical
   headlines** from different sources into one story: titles are
   compared as sets of word tokens longer than two characters, and a
   Jaccard similarity ≥ 0.6 against a cluster's primary counts as the
   same story. The freshest telling leads the cluster; the others become
   its `alsoCoveredBy` members instead of being dropped
   (`Builder::clusterNearDuplicates`, semantics in
   docs/rating-system.md §5).
3. **Edition filter.** Items from sources marked `edition: false`
   (collection-only sources, see docs/collections.md §4) are skipped —
   they never enter topic classification.
4. **Language routing.** If the item's source lists `de` in `languages`,
   the German keyword set and German matching rules apply; otherwise the
   English set. The *source's* language decides, not the text — a German
   outlet quoting an English phrase is still matched as German.
5. **Keyword scoring.** For each topic in `TOPIC_ORDER`
   (the founding topics climate → peace → digital-rights →
   accessibility first, then health → economy → democracy → migration →
   science), count how many of its keywords match title + summary
   (lower-cased). The topic with the **strictly highest** hit count
   wins.
   - Tie-breaking: because the comparison is `hits > bestHits`, on a tie
     the topic **earlier in `TOPIC_ORDER`** wins.
   - A single hit is sufficient — this is why keyword precision matters
     (see §3).
6. **Specialist fallback.** If no keyword matched: sources with exactly
   one topic that is not `general` (e.g. Mongabay → climate,
   netzpolitik.org → digital-rights) contribute the item to that topic.
7. **Generalist drop.** Items from generalist sources with no keyword
   match are discarded. Topic specialisation over generalism is a design
   commitment, not a recall bug.

## 2. Matching strategies (the core of the design)

One strategy per keyword shape, chosen to fit each language's morphology:

| Keyword shape | Strategy | Rationale / example |
|---|---|---|
| Contains a space (any language) | substring | phrases: "künstliche intelligenz", "net zero" |
| German, length ≤ 4 | substring **with letter boundaries** (`(?<!\p{L})kw(?!\p{L})/u`) | "nato" must match "Nato-Gipfel" (hyphen = boundary) but **not** "Se**nato**ren" or "Koordi**nato**ren" |
| German, length > 4 | plain substring | German compounds *contain* but don't *start with* the keyword: "Atom**waffen**", "Ukraine**krieg**" — token matching would miss them |
| English, length ≤ 4 | exact token | "war" must not match "**war**ning", "a**war**d"; tokens are `[a-z0-9]+` runs |
| English, length > 4 | token prefix | "emission" matches "emission**s**", "sanction" matches "sanction**s**" |

The asymmetry is deliberate: German compound morphology makes substring
matching necessary and token matching useless; English morphology makes
token matching safe and substring matching dangerous.

## 3. Keyword curation rules

Learned from real false positives (all covered by regression tests):

1. **No bare short words as German substrings.** `nato` needed letter
   boundaries (→ "Senatoren" bug).
2. **Prefer the domain-specific inflection.** `fossile` (adjective, as in
   "fossile Brennstoffe") instead of `fossil` — which matched
   "Fossilienfunde" (paleontology). `emissionen` (plural, climate usage)
   instead of `emission` — which matched financial bond issuance
   ("Emission neuer Anleihen").
3. **Drop words whose common usage is broader than the topic.**
   `konflikt` was removed: "Tarifkonflikt" (labour dispute) is not a
   peace/security story. Armed-conflict recall is carried by `krieg`,
   `gefecht`, `waffenruhe`, `waffenstillstand` etc.
4. **Compound-safety check for every new German keyword:** think of
   unrelated words *containing* the keyword before adding it
   (`…nato…`, `…flut…` → "Flut von Anfragen" is why bare `flut` is not
   in the list; `hochwasser`/`flutkatastrophe` are).
5. **Every fixed false positive gets a test** in `tests/EditionTest.php`
   so it cannot regress silently.

### Known, accepted limitations

Single-keyword classification cannot see context. Currently accepted
false-positive classes (documented rather than over-engineered away):

- EN `arms` as exact token still matches idioms ("open arms").
- EN `platform` matches political platforms, not only tech platforms.
- DE `teilhabe` is broader than disability inclusion (labour-market
  usage), kept because German disability discourse centres on the term.
- DE `emissionen` still matches "Anleihe-Emissionen" (rare in practice).
- Compound recall gaps for boundary-matched short keywords: a spaceless
  compound like "Natomitglied" (usually written "Nato-Mitglied") is
  missed; likewise DE `asyl` and `wahl` miss singular spaceless
  compounds ("Asylpolitik", "Bundestagswahl") — the long compounds and
  plural forms in the lists carry recall.
- EN `migration` also matches bird and data migration; DE `verfassung`
  also matches the physical-condition idiom ("in guter Verfassung");
  DE `forschung` also matches "Marktforschung" (an economy story) — all
  kept because hit counts usually resolve them and the domain usage
  dominates news coverage.

The structural fix for all of these is context-aware (LLM-assisted)
classification — tracked in TODO.md as an open concept question.

## 4. Where category detection surfaces in the product

- **Edition page (`/`)**: compact mode selects from the classified pool
  (see `docs/rating-system.md` §5); full mode shows the entire pool.
- **Categories page (`/categories`)**: per-topic 48-hour counts, latest
  detections, specialist sources, and the live keyword lists (rendered
  from `Classifier::KEYWORDS_DE/EN`, so the page can never drift from
  the code).
- **Sources page (`/sources`)**: per-source "Im Themenfokus" counts from
  the same classified pool.

## 5. Extending

- New keyword: follow §3, add a regression test if the word has any
  ambiguity, run `vendor/bin/phpunit`.
- New topic: define it in `data/topics.yaml` first (IPTC concepts, §6),
  then extend `TOPIC_ORDER`, both keyword maps, `Registry::TOPICS`,
  the topic label + description in both message catalogs
  (`translations/de.php`, `translations/en.php` under `topics.*`) and
  the topic-count copy (`categories.*`, `methodology.selection`), a
  `topic-<id>` icon in `templates/_icons.html.twig` (official Lucide
  path — fetch it, do not write it from memory), and
  flip the vocabulary entry to `status: active`;
  note that quota math (12 total) gives 9 non-empty topics a base quota
  of 1, with round-robin top-up favouring earlier `TOPIC_ORDER` entries.
- New language: add a keyword map and a routing rule in
  `Classifier::classify()`; decide the matching strategy from the
  language's morphology, not by copying either existing one.

## 6. Topic vocabulary (IPTC Media Topics)

Every focus topic is *defined* as a curated selection of concepts from
[IPTC Media Topics](https://cv.iptc.org/newscodes/mediatopic/) — the
industry-standard news taxonomy (~1,100 concepts, 13 languages including
German, mapped to Wikidata, licensed CC BY 4.0 by IPTC). The mapping
lives in `data/topics.yaml`, is loaded by `Registry\Topics` and enforced
by `bin/meridian sources:validate`.

The vocabulary states what each topic *means*; the keyword classifier
(§1–§2) remains the operative detection mechanism. A future
context-aware classifier (TODO.md) should emit IPTC concepts and resolve
them to topics through this mapping instead of new keyword lists.

### Semantics

- **A code claims its subtree**: listing `medtop:06000000` (environment)
  covers every narrower concept, e.g. climate change
  (`medtop:20000418`).
- **Literal uniqueness**: the validator rejects the same code in two
  topics. Codes must be current (non-retired) at adoption time — check
  against the vocabulary release before adding one (e.g. integration
  policy `medtop:20001146` was retired 2025-03 without a successor).
- **Nesting is allowed and deliberate** — e.g. `democracy` claims
  fundamental rights (`medtop:20000587`) while `digital-rights` claims
  its children privacy, censorship and freedom of the press. Precedence:
  the **deepest matching concept wins**; ties fall back to
  `TOPIC_ORDER`, mirroring §1's tie-breaking.
- **Statuses**: `active` topics must be exactly `Registry::TOPICS` minus
  `general` (validator-enforced in both directions); `proposed` topics
  are agreed direction not yet wired into the classifier — the
  activation checklist is §5 "New topic".

### Current mapping

| Topic | Status | IPTC concepts (German / English prefLabel) |
|---|---|---|
| `climate` | active | `medtop:06000000` Umwelt / environment · `medtop:20000423` Umweltpolitik / environmental policy · `medtop:20000257` Erneuerbare Energie / renewable energy |
| `peace` | active | `medtop:16000000` Krise, Krieg, Konflikte / conflict, war and peace · `medtop:20000598` Verteidigung / national security · `medtop:20000642` Wirtschaftliche Sanktion / economic sanction |
| `digital-rights` | active | `medtop:20001300` Datenschutz / privacy · `medtop:20000627` Datenschutz / data protection policy · `medtop:20001299` Überwachung / surveillance · `medtop:20000588` Zensur / censorship and freedom of speech · `medtop:20000591` Pressefreiheit / freedom of the press · `medtop:20001298` Künstliche Intelligenz / artificial intelligence |
| `accessibility` | active | `medtop:20000791` Behinderung / disabilities · `medtop:20001373` Diversität, Gerechtigkeit und Integration / diversity, equity and inclusion |
| `health` | active | `medtop:07000000` Medizin, Gesundheit / health · `medtop:20000479` Gesundheitspolitik / healthcare policy |
| `economy` | active | `medtop:20000344` Wirtschaft / economy · `medtop:09000000` Arbeit, Soziales / labour · `medtop:20000345` Wirtschaftspolitik / economic policy · `medtop:20000630` Sozialpolitik / pension and welfare policy |
| `democracy` | active | `medtop:20000654` Demokratie / democracy · `medtop:20000574` Wahl / election · `medtop:20000587` Grundrechte / fundamental rights · `medtop:20000615` Parlament / legislative body · `medtop:20000597` Verfassung / constitution (law) · `medtop:20000093` Korruption / corruption · `medtop:20000106` Justiz / judiciary |
| `migration` | active | `medtop:20000771` Einwanderung / immigration · `medtop:20000645` Flüchtling / refugees and internally displaced people · `medtop:20000634` Migration / immigration policy |
| `science` | active | `medtop:13000000` Wissenschaft, Technik, Forschung / science and technology |

Deliberately not adopted: education (thin daily coverage in the current
source set — revisit with education specialists), arts/culture and sport
(well served everywhere; Meridian's mandate is fields where perspective
diversity is missing), human interest / lifestyle (engagement bait, the
opposite of the product's promise).

Attribution: topic definitions reference the IPTC Media Topics NewsCodes,
© IPTC, licensed under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).
