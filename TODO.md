# TODO

## Active

- [ ] Migration still has no German-language specialist source —
      Mediendienst Integration publishes no RSS feed; check again or
      find an alternative (Fluchtforschung.net? IOM/UNHCR newsrooms are
      state-adjacent and need careful rating)

- [ ] Context-aware (LLM-assisted) classification for the accepted
      false-positive classes documented in docs/classification.md §3
- [ ] Translation/framing layer: short German framing lines for
      non-German articles (concept: MT with human spot-check); mark
      machine-translated lines visibly (Ground-News-style marker icon,
      tooltip with the original title) — never present MT as original
      wording
- [ ] Euractiv feed is Cloudflare-flaky (works intermittently) — find a
      stable EU-affairs feed or accept the gaps
- [ ] Read tracking needs JavaScript (`sendBeacon`); without it the
      balance page stays empty. A no-JS fallback would need link
      rewriting, which was rejected on privacy grounds — revisit only if
      it can be done without touching the article URL

## Ideas

From the 2026-08-27 review of Ground News, AllSides and Verity News:

- [ ] Story clustering phase 2 (decided 2026-08-27): swap the shipped
      Jaccard heuristic for similarity/embedding clustering behind the
      same interface, once phase 1 shows its limits — semantics in
      rating-system.md §5 must not change with it
- [ ] LLM framing comparison per story cluster (how each side frames
      it, the divide, the agreement — Ground News "Bias Comparison"
      pattern) via the OpenAI-compatible client, extending the
      translation/framing item; clusters exist since phase 1
- [ ] Good-news pool is thin (two sources): evaluate enorm Magazin
      (feed unreachable 2026-08-27) and Perspective Daily (no public
      feed) again, or an English constructive source (Reasons to be
      Cheerful, Positive News)
- [ ] Let signed-in readers add RSS feeds (hard-capped like the
      watchlist, fetched by the daily cron, private to the reader — never
      in the shared edition): resolve known outlets through Wikidata
      (official-website match → QID, ownership, country); unrated sources
      get an explicit "unrated" state on every card and in the balance
      accounting, auto-collected facts count as evidence, never as
      judgement; promotion into the public dataset goes through a curator
      review modelled on the classification-report flow
- [ ] Newsletter output next to the web edition (concept names it as
      possible primary distribution) — accounts now supply the address
      and the frequency setting, mail delivery is what is missing
- [ ] Balance page: compare against what was actually *published* in the
      period, not only the dataset composition — needs a rolling archive
      of past editions (depends on the archive item above)
- [ ] Publish `data/sources/` as standalone open dataset with JSON export
- [ ] Browser-extension form: overlay classification on news sites
- [ ] More locales (fr, es) — infrastructure is ready, catalogs +
      keyword maps needed

## Done

- [x] Blindspot line extended to perspectives, measured against the
      possible (decided 2026-08-28): Section::missingPerspectives reports
      a perspective only when it had fresh candidates for the topic
      (cluster members count as visible); rating-system.md §5
- [x] Two-axis coverage grid completed on /sources: the dataset's band
      shape next to the spectrum map (the map places outlets, the grid
      shows which combinations exist at all) — section head and balance
      overlay had shipped earlier
- [x] Source-anchored dissent entry: "Bewertung anfechten" on every
      /sources card → /report?source=<id>, kinds rating/source/other,
      resolved against the registry (forgery property holds), stored
      with empty url/title/topic; reports:list tells both kinds apart —
      accounts.md §6/§11
- [x] Story clustering phase 1 (rating-system.md §5): near-identical
      headlines within a topic cluster into one story instead of being
      dropped — the freshest telling leads, the others render as an
      "also covered by" line and resolve through findFresh so reads
      attribute to the source actually read; caps now count stories
- [x] Two-axis coverage grid (economic × cultural, AxisGrid +
      _axis_grid.html.twig): replaces the per-section economic bar and
      shows the reader's period on /balance with the dataset as dashed
      reference; /sources keeps its continuous spectrum map, which
      already shows the dataset shape
- [x] Perspective Duel shipped as the first algorithmic collection
      (collections.md §7): story clusters whose outermost tellings sit
      ≥ 2 economic bands apart, headline against headline on
      /collections, read-only, cap 6
- [x] Self-hosted favicons: favicons:fetch mirrors source icons into
      public/favicons (git-ignored, cron after fetch); article cards,
      source list and duel cards show them without third-party requests
- [x] Edition archive (rating-system.md §6): editions:archive freezes the
      compact edition to data/archive/YYYY-MM-DD.json (idempotent daily
      cron after fetch); /archive lists days, /archive/<date> renders a
      frozen edition read-only with current-dataset ratings labelled as
      such; ArchiveTest
- [x] Specialist freshness window: sources with exactly one focus topic
      reach back 336 h instead of 48 h — Leidmedien/Die Neue Norm
      surface again (classification.md §1, regression test)
- [x] Edition blindspot line: sections with ≥2 articles name a missing
      economic side (Section::missingEconomicSides, rating-system.md §5)
- [x] Overflow "…" menu in the top bar: Methodik and Archiv moved into a
      no-JS details dropdown (progressive-enhancement outside-click
      close) — room for future secondary pages
- [x] Curated collections shipped (/collections, spec in
      docs/collections.md): Good News (Squirrel News, Good News
      Magazin), For Children (logo!, Duda.news, Kindersache), Simple
      Language (nachrichtenleicht), Global South Lens — explicit
      inclusion criteria in both catalogs, finite caps, read-only
      cards; collection-only sources carry `edition: false` and stay
      out of the topic edition (dataset now 33 sources)
- [x] Nine specialist sources recruited for the new topics (dataset now
      27): Deutsches Ärzteblatt + Bhekisisa (health), Makronom +
      Wirtschaftliche Freiheit + Social Europe (economy, spanning
      −2.0…+2.5 on the economic axis), Verfassungsblog + OCCRP
      (democracy), The New Humanitarian (migration), Spektrum der
      Wissenschaft (science — ownership moved from Springer Nature to
      GeraNova Bruckmann in June 2026, most external profiles are
      stale); all with evidence-backed ratings incl. the uncomfortable
      parts (Gates dependency at Bhekisisa, the disputed 52%
      US-government funding share at OCCRP), feeds verified end-to-end
- [x] Five new focus topics activated (health, economy, democracy,
      migration, science): keyword maps DE/EN per classification.md §3,
      TOPIC_ORDER + Registry::TOPICS, labels/descriptions and topic-count
      copy in both catalogs, quota example in rating-system.md §5,
      regression tests per topic incl. tie-break (Klimaforschung) and
      deliberate omissions (Integration)
- [x] IPTC Media Topics adopted as the topic vocabulary: data/topics.yaml
      maps every focus topic to IPTC subtrees, Registry\Topics validates
      codes, uniqueness and sync with Registry::TOPICS via
      sources:validate; spec in docs/classification.md §6, TopicsTest
- [x] Wikidata identity link for sources: optional outlet-level QIDs,
      canonical URLs, offline syntax/uniqueness validation, and 15 verified
      mappings (specialists without a verified item remain explicit gaps)
- [x] Optional accounts via OIDC (authorization code + PKCE, verified ID
      tokens, hashed server-side sessions, CSRF on every write): settings
      sync, cross-device read state with soft daily limit, reading-balance
      dashboard (`/balance`), capped watchlist, classification reports
      with CLI review, JSON export and immediate account deletion —
      spec in docs/accounts.md, 51 tests

- [x] Publishers/media-houses section (/publishers, Ground-News-style
      media-ownership view): `publisher` field on every source, grouped by
      house with concentration first; publisher shown on source cards;
      ownership categories now localized; DE/EN copy; PublisherTest
- [x] Go prototype (quellenkompass) with rating model, fetcher, static
      daily edition
- [x] Port to PHP per the "Language & Software-Shape" heuristic; name
      decided: **Meridian**
- [x] Modern web UI; header editorial, content area sober/utilitarian
      (2026-07-10 redesign pass)
- [x] Two reading modes: Compact (default, hard caps) and Full
- [x] Audit of rating system + category detection with six fixes, all
      regression-tested (13 tests)
- [x] Technical specs: docs/rating-system.md, docs/classification.md
- [x] Overview pages /sources (spectrum map, evidence) and /categories
      (live counts, keywords)
- [x] English URLs and internal identifiers (/sources, /categories,
      /methodology, ?mode=compact|full)
- [x] i18n: DE (default) + EN message catalogs, ?lang= switcher with
      cookie, locale-aware spectrum labels and dates
- [x] Transparency: /methodology page with rating model, selection rules
      and a 17-term glossary (both languages)
- [x] Dataset: right/TAN side filled (Welt), accessibility specialists
      added (Die Neue Norm, Leidmedien) — 18 sources
- [x] Near-duplicate headline dedup across sources (token Jaccard ≥ 0.6)
- [x] Fonts self-hosted (GDPR) — no third-party requests
