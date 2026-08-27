# Rating Methodology

> Editorial companion to the technical specs: exact value semantics, band
> boundaries and the selection algorithm are specified in
> [docs/rating-system.md](docs/rating-system.md); category detection in
> [docs/classification.md](docs/classification.md).

Meridian classifies media sources on the **European understanding of
the political spectrum**, not the US left/right binary that existing tools
(Ground News, AllSides, Media Bias/Fact Check) project onto European media.
This document defines the model, the scales, and the rules for assigning
and revising ratings. The dataset in `data/sources/` is an open dataset:
every rating must be explainable from this document plus its evidence links.

## Why not the US model

US-centric bias raters fail on European media in predictable ways:

1. **One axis is not enough.** The US model collapses politics onto a
   single liberal↔conservative axis. European party systems separate the
   *economic* question (redistribution vs. market) from the *cultural*
   question (open/libertarian vs. traditional/authoritarian). A source can
   be market-liberal and culturally progressive (typical liberal press) or
   state-interventionist and culturally conservative — the US model cannot
   express either.
2. **The centre sits elsewhere.** The European centre assumes public
   healthcare, strong labour law, and public broadcasting. Rating European
   outlets against the US centre shifts everything two notches left and
   makes "centrist" labels meaningless.
3. **Public service is not state media.** US raters routinely tag European
   public-service broadcasters as "state-funded" alongside actual state
   propaganda outlets. Licence-fee funding under pluralistic council
   governance is a different category from government control and the
   dataset encodes it as such.
4. **EU integration is its own conflict line.** Pro/anti-integration cuts
   across the left/right spectrum and shapes coverage of most European
   policy debates. It needs its own axis.

## The model

Every source carries a `rating` block with these dimensions:

### Political position (three axes, each -3.0 .. +3.0)

| Axis | -3 | 0 | +3 |
|---|---|---|---|
| `economic` | redistributive left (public ownership, strong welfare) | European centre | market-liberal right (privatisation, low regulation) |
| `cultural` | **GAL** — green / alternative / libertarian | — | **TAN** — traditional / authoritarian / nationalist |
| `eu_stance` | hard eurosceptic (exit/dissolution) | neutral / no line | federalist (deeper integration) |

The two main axes follow the **Chapel Hill Expert Survey (CHES)**
convention used in European comparative politics; CHES party positions are
the calibration anchor: a source whose editorial line tracks a party's
positions should land near that party's CHES placement (rescaled to
-3..+3). Values are editorial-line judgements about opinion sections and
framing choices, **not** about factual accuracy (that is `reliability`).

For display, values bucket into five bands per axis
(see `src/Spectrum`): < -1.75 / -1.75..-0.5 / -0.5..+0.5 /
+0.5..+1.75 / > +1.75.

### `party_family`

The closest **European party family**, as a coarse, human-readable anchor:
`left`, `green`, `social-democratic`, `liberal`, `christian-democratic`,
`conservative`, `national-conservative`, `right-populist`, or
`nonpartisan`. Use `nonpartisan` for outlets whose line does not track a
party family (public broadcasters, single-issue specialists, agencies) —
even when their issue positions have a discernible lean, which the axes
capture.

### `reliability` (1–5)

Quality of factual reporting, independent of political position:
sourcing discipline, corrections policy, separation of news and opinion,
track record of fabrications or systematic blind spots. A source with a
strong political line can score 5; a centrist source with sloppy sourcing
scores low. Documented systematic blind spots (e.g. a funder the outlet
cannot report on) cap the score at 3.

### `state_influence`

`none` · `indirect` (public-service under pluralistic council governance)
· `state-affiliated` (funded/owned by a state without editorial-
independence guarantees) · `state-controlled` (editorial line set by
government). This four-step scale exists precisely because "publicly
funded" and "state-controlled" must not be conflated.

### `transparency`

`low` / `medium` / `high` — does the outlet disclose ownership, funding
and editorial responsibility? Independent of what that disclosure reveals.

### `confidence` and `evidence`

Every rating states how solid it is (`low`/`medium`/`high`). Ratings with
medium or high confidence **must** cite evidence (URLs + note): ownership
disclosures, media-research profiles (eurotopics, EJO, Reuters Institute
Digital News Report), documented editorial self-descriptions. The
validator enforces this. Low confidence is honest and allowed — it flags
ratings awaiting better grounding, it does not excuse skipping research.

### Perspective (source-level, not part of `rating`)

`dach` / `europe` / `global-south` / `international` describes the
geographic vantage point of the newsroom and its reporting network — where
the outlet reports *from*, not where its HQ is registered (Mongabay is
`global-south` by correspondent network despite a US HQ). Perspective is
the primary diversity dimension when editions are balanced.

### Publisher (source-level, not part of `rating`)

`publisher` names the media house behind the outlet — the actual owning
or publishing entity (e.g. `Axel Springer SE`, `SOZIALHELDEN e. V.`), not
the `ownership` *category* (`private`, `foundation`, …). The `/publishers`
page groups sources by this field to surface **media ownership and its
concentration**: where one house speaks through several titles it appears
as a group. The name must be verifiable from the outlet's own imprint or
the evidence links; when in doubt use the directly publishing entity
rather than asserting a parent group. The validator requires it to be
non-empty.

### Wikidata identity (source-level, not part of `rating`)

`wikidata` is the optional canonical QID of the **outlet itself**. It links
the curated source record to Wikidata's CC0 knowledge graph so publisher and
ownership claims can later be compared with structured external data. It
does not replace the curated `publisher` value or rating evidence, and a QID
for a parent company, publisher or similarly named organisation must not be
substituted when the outlet has no item. Absence therefore means "no verified
outlet-level item", not "research complete".

## Rules for raters

1. **Rate the editorial line, not individual articles.** Articles inherit
   their source's rating.
2. **Anchor against CHES party placements**, not gut feeling and not the
   US spectrum.
3. **Never launder reliability through politics.** Disagreeing with a
   line is not a reliability deduction; fabrication, missing corrections,
   and blind spots are.
4. **State the uncomfortable parts in the evidence note** — e.g. Al
   Jazeera's Qatar blind spot, Spiegel's Relotius history, Euractiv's EU
   project funding. The dataset earns trust by being blunt about its own
   sources.
5. **Revisit on ownership or leadership change**, otherwise yearly.
6. Ratings are **contestable by design**: the dataset is open, every
   change should reference evidence, disagreements get resolved by better
   evidence, not by averaging.

## How ratings drive the product

The daily edition builder (`src/Edition`) uses ratings for balance,
never for exclusion by politics:

- **Perspective diversity weighs most** (Global-South visibility is the
  product's reason to exist), then economic-band spread, then cultural.
- **Reliability breaks ties** between equally diversifying picks.
- Sources are excluded only via the dataset itself (a source with no entry
  does not exist for the product), never silently at build time.
- Every article displays its source's full classification — the point is
  an informed reader, not a filtered one.
