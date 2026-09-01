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
- [ ] Die Neue Norm and The Hindu return 403 from the production
      server, so the live edition carries fewer sources than local runs.
      Measured 2026-08-28, not inferred: identical User-Agent gives 200
      from a residential IP and 403 from the server's datacenter IP, and a browser
      User-Agent from the server stays 403 — this is the source IP, so
      UA cosmetics cannot fix it. Realistic remedy is a different egress
      for the fetch (proxy/relay outside the datacenter range), which
      needs a decision on where that egress lives
- [ ] Euractiv is a separate case from the two above: it 403s from every
      IP tested, residential included, and across three User-Agents —
      find a stable EU-affairs feed or accept the gaps
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
- [ ] Culture & media as tenth focus topic ("Kultur & Medien"):
      mission rationale is Global-South visibility (cultural coverage
      is heavily DACH/Western); quota math stays at base 2 with ten
      topics. Gate on LLM-assisted classification (item above) —
      culture vocabulary (film, musik, theater) is the most
      false-positive-prone yet for substring matching
- [ ] Publish `data/sources/` as standalone open dataset with JSON export
- [ ] Browser-extension form: overlay classification on news sites
- [ ] More locales (fr, es) — infrastructure is ready, catalogs +
      keyword maps needed

## Done

- [x] Split AccountRoutes into SignInRoutes, AccountRoutes and
      ReadingRoutes (2026-09-02); the guards moved to `AccountGuard`, the
      one-message page to `View::message()`, and App builds the
      `OidcClient` once for both groups that need it. Story clustering
      left the Builder for `StoryClusterer` with its own tests
- [x] Split App into route groups (2026-09-01): `EditionRoutes`,
      `DatasetRoutes` and `ContentPages` (the renamed `LegalPages`, now
      also `/methodology`) take Request and View and return null for
      paths they do not own, like `AccountRoutes` already did; App keeps
      wiring, viewer, locale and the 404. Archive rehydration moved to
      `Archive::restore()`, the cache fallback to
      `ItemCache::loadOrEmpty()`. The public pages have render tests now
- [x] Evidence and keyword lists open in a modal (2026-09-01): one
      shared native `<dialog>` in the layout lifts every
      `details.modal` — the details' content moves into the dialog and
      back on close, so the card grid never reflows; Escape, backdrop
      click and the close button close it, focus returns to the
      summary; without JavaScript the `<details>` opens inline as before
- [x] Spectrum map section (2026-09-01): head in the edition's two-line
      form with the small grid (titling/context/spread now live in the
      layout), the whole section a `<details>` whose collapsed state is
      remembered in localStorage (renders open, no-JS stays open); map
      gets band strips in the gauge colours along both axes, dashed
      band boundaries matching Spectrum\Band, uppercase axis labels,
      label halos and a hover accent per dot
- [x] Design pass after the category bar (2026-09-01): priority+ topbar
      (all pages inline from 76 rem, Kategorien/Archiv/Methodik in the
      ellipsis below, mode switch drops to its own row below 60 rem);
      category bar with hidden scrollbar, edge fades, a scroll-spy
      active state and hover-only chevron buttons that page the bar
      (layout wraps the subnav block in `.catrail`; buttons stay
      hidden without JS and on touch); spectrum map laid out by `Web\SpectrumMap` (display
      names, coincident dots spread on a ring, labels only where they
      collide with nothing, the rest numbered inside the dot with an
      index below — SpectrumMapTest); one-title publishers collapsed
      into one card ("ein Titel" gone); perspective colours on pills
      and section heads (`persp-*` classes, global-south darkened for
      contrast); blind-spot line with eye-off icon and accent edge;
      axis gauges as diverging bars from the centre (`_gauge.html.twig`)
- [x] Category bar under the topbar, Ground-News-style (2026-09-01):
      the edition's topic pill row became a shared `subnav` rail in the
      layout (`.catbar`); edition, /categories and /archive/<date> fill
      it with icon + label + count anchors; scrolls horizontally inside
      itself on narrow viewports (375 px measured, no page overflow)
- [x] Section head split into two lines (2026-09-01): the axis grid
      already gives the row its height, so article count and
      perspectives moved below the topic title; grid stays right,
      wraps under on narrow viewports (measured at 375 px, no
      horizontal overflow). Archive heads (title + count only) are
      untouched
- [x] First-visit welcome box on the edition page (2026-08-31): what
      Meridian is and why, the three axes with GAL–TAN/DACH spelled
      out, what the spectrum grid shows, link to /methodology; both
      catalogs under welcome.*; dismiss via /?welcome=off persists the
      meridian-welcome cookie — no JS, no account, signed-in viewers
      never see it
- [x] Blindspot-directed surplus (2026-08-30): compact selection spends
      spare capacity above the base quota on closing reported blind
      spots first — a missing economic side or perspective with an
      available fresh candidate — before the round-robin fallback;
      replaces the founding-topic preference (rating-system.md §5,
      regression test with a discriminating fixture)
- [x] Compact cap raised 12 → 20 (2026-08-30): the 12 predated the topic
      expansion — at nine topics it pinned five sections to one article,
      where no balance, blindspot line or axis grid can exist; base
      quota is now 2 per topic. Fair-quota test rebuilt: squeezed topic
      is now science (last in TOPIC_ORDER), titles share only the topic
      keyword so story clustering no longer collapses the fixture (that
      had silently defanged the old test), and saturation is pinned via
      total() === MAX_ITEMS_TOTAL
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
- [x] Legal pages: /impressum + /privacy (Twig, bilingual privacy, German
      authoritative), /datenschutz → 301, footer links on every page,
      draft guard (TODO in rendered output ⇒ banner), OIDC provider named
      dynamically from config (LegalPagesTest)
- [x] Hosting/server-log section filled (deployed on own Hetzner server (Hetzner
      Online GmbH, EU) — no third-country transfer) and Stand/version date
      set; draft banner cleared
- [ ] Have the § 18 MStV framing (news aggregator = journalistic-
      editorial?) sanity-checked by counsel at the next legal touchpoint
- [ ] Confirm the Hetzner data processing agreement (AVV) is concluded in
      the Hetzner account panel; the privacy policy §3 references it
- [x] http-foundation as the HTTP boundary: Request::fromGlobals() parses
      via Symfony (trusted proxies, MERIDIAN_TRUSTED_PROXIES env),
      Response::send() emits via Symfony (headers, cookie serialization);
      the app-facing value objects stay Meridian's own
- [ ] symfony/routing instead of the match() in App::route — only when the
      route table outgrows ~20 entries or needs more parameterized routes;
      until then the match expression is the more readable form
- [ ] symfony/translation instead of I18n\Translator — only when plural
      rules or ICU message formatting become necessary; the PHP array
      catalogs carry two locales fine
- [ ] Full Symfony switch when the time is ripe — triggers: security
      needs beyond the OIDC session (roles, 2FA), growing form/CSRF
      surface, a route table past ~20 entries, or additional contributors
      expecting standard structure. The component steps (http-foundation
      done, routing and translation above) shorten that path; migrate in
      instalments, not big-bang.
