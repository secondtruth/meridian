<?php

declare(strict_types=1);

namespace Meridian\Tests;

use Meridian\Edition\Article;
use Meridian\Edition\Builder;
use Meridian\Edition\Classifier;
use Meridian\Edition\Mode;
use Meridian\Edition\Section;
use Meridian\Feed\Item;
use Meridian\Registry\Rating;
use Meridian\Registry\Registry;
use Meridian\Registry\Source;
use PHPUnit\Framework\TestCase;

final class EditionTest extends TestCase
{
    /** @param list<string> $topics */
    private static function source(string $id, string $perspective, array $languages, float $economic, array $topics): Source
    {
        return new Source(
            id: $id, name: $id, country: 'DE', languages: $languages,
            type: 'online', ownership: 'private', publisher: $id, funding: [],
            perspective: $perspective, topics: $topics, homepage: '', feeds: [],
            rating: new Rating($economic, 0.0, 0.0, 'nonpartisan', 4, 'high', 'none', 'high'),
        );
    }

    private static function item(string $sourceId, string $title, string $link, string $summary = ''): Item
    {
        return new Item($sourceId, $title, $link, $summary, new \DateTimeImmutable());
    }

    public function testGermanVerbWarIsNotClassifiedAsWar(): void
    {
        $source = self::source('nzz', 'dach', ['de'], 2.0, ['general']);
        $item = self::item('nzz', 'Urteil gegen Le Pen bestätigt', 'https://x/1',
            'Das Urteil war erwartet worden, eine Kandidatur bleibt möglich.');

        self::assertNull((new Classifier())->classify($item, $source));
    }

    public function testEnglishWarMatchesButWarningDoesNot(): void
    {
        $source = self::source('aljazeera', 'global-south', ['en'], 0.0, ['general']);
        $classifier = new Classifier();

        $war = self::item('aljazeera', 'Ceasefire talks stall as war enters third year', 'https://x/2');
        self::assertSame('peace', $classifier->classify($war, $source));

        $warning = self::item('aljazeera', 'Storm warning issued for coastal regions', 'https://x/3');
        self::assertNotSame('peace', $classifier->classify($warning, $source));
    }

    public function testGermanCompoundMatches(): void
    {
        $source = self::source('taz', 'dach', ['de'], -2.0, ['general']);
        $item = self::item('taz', 'Debatte über Atomwaffen in Europa', 'https://x/4');

        self::assertSame('peace', (new Classifier())->classify($item, $source));
    }

    public function testSpecialistFallbackAppliesOnlyToSpecialists(): void
    {
        $classifier = new Classifier();
        $specialist = self::source('mongabay', 'global-south', ['en'], 0.0, ['climate']);
        $generalist = self::source('zeit', 'dach', ['de'], 0.0, ['general']);
        $item = self::item('mongabay', 'Interview: community land rights in Borneo', 'https://x/5');

        self::assertSame('climate', $classifier->classify($item, $specialist));
        self::assertNull($classifier->classify(self::item('zeit', 'Interview über Landrechte', 'https://x/6'), $generalist));
    }

    public function testCompactModeEnforcesCapsAndDiversity(): void
    {
        $registry = new Registry([
            self::source('left-de', 'dach', ['de'], -2.0, ['general']),
            self::source('right-de', 'dach', ['de'], 2.0, ['general']),
            self::source('south', 'global-south', ['en'], 0.0, ['general']),
        ]);

        $items = [];
        foreach (['left-de', 'right-de'] as $id) {
            for ($i = 0; $i < 20; ++$i) {
                $items[] = self::item($id, "Klimakrise Update {$id}: Ereignis{$i} Region{$i} Detail{$i}", "https://x/{$id}/{$i}");
            }
        }
        $items[] = self::item('south', 'Climate finance for the Global South', 'https://x/south/1');

        $edition = (new Builder())->build($registry, $items, new \DateTimeImmutable(), Mode::Compact);

        self::assertLessThanOrEqual(Builder::MAX_ITEMS_TOTAL, $edition->total());
        $foundSouth = false;
        foreach ($edition->sections as $section) {
            self::assertLessThanOrEqual(Builder::MAX_ITEMS_PER_TOPIC, count($section->articles));
            $seen = [];
            foreach ($section->articles as $article) {
                self::assertArrayNotHasKey($article->source->id, $seen, 'source repeated within section');
                $seen[$article->source->id] = true;
                $foundSouth = $foundSouth || $article->source->id === 'south';
            }
        }
        self::assertTrue($foundSouth, 'Global-South source must win against 40 DACH items');
    }

    public function testGermanShortKeywordsRequireWordBoundaries(): void
    {
        $source = self::source('spiegel', 'dach', ['de'], 0.0, ['general']);
        $classifier = new Classifier();

        // "Senatoren" and "Koordinatoren" contain "nato" as a substring;
        // the item is a budget story ("Haushalt" → economy), never peace.
        $senators = self::item('spiegel', 'Senatoren stimmen über Haushalt ab', 'https://x/10');
        self::assertSame('economy', $classifier->classify($senators, $source));

        $nato = self::item('spiegel', 'Nato-Gipfel: Bündnis berät über Ostflanke', 'https://x/11');
        self::assertSame('peace', $classifier->classify($nato, $source));
    }

    public function testGermanNonClimateHomonymsAreNotClimate(): void
    {
        $source = self::source('faz', 'dach', ['de'], 1.5, ['general']);
        $classifier = new Classifier();

        $fossils = self::item('faz', 'Neue Fossilienfunde in Bayern entdeckt', 'https://x/12');
        self::assertNull($classifier->classify($fossils, $source));

        $bonds = self::item('faz', 'Die Emission neuer Anleihen stockt', 'https://x/13');
        self::assertNull($classifier->classify($bonds, $source));

        $fuels = self::item('faz', 'Streit über Subventionen für fossile Brennstoffe', 'https://x/14');
        self::assertSame('climate', $classifier->classify($fuels, $source));
    }

    public function testLabourDisputeIsEconomyNotPeace(): void
    {
        // "konflikt" was removed from the peace list because labour
        // disputes are not security stories; with the economy topic they
        // now land where they belong ("Tarif…").
        $source = self::source('taz', 'dach', ['de'], -2.0, ['general']);
        $item = self::item('taz', 'Tarifkonflikt bei der Bahn spitzt sich zu', 'https://x/15');

        self::assertSame('economy', (new Classifier())->classify($item, $source));
    }

    public function testHealthClassification(): void
    {
        $classifier = new Classifier();
        $de = self::source('spiegel', 'dach', ['de'], 0.0, ['general']);
        $en = self::source('aljazeera', 'global-south', ['en'], 0.0, ['general']);

        self::assertSame('health', $classifier->classify(
            self::item('spiegel', 'Neue Therapie gegen Demenz zeigt Wirkung', 'https://x/20'), $de));
        self::assertSame('health', $classifier->classify(
            self::item('aljazeera', 'Hospital staff shortage worsens patient care', 'https://x/21'), $en));
    }

    public function testEconomyClassification(): void
    {
        $classifier = new Classifier();
        $de = self::source('faz', 'dach', ['de'], 1.5, ['general']);
        $en = self::source('aljazeera', 'global-south', ['en'], 0.0, ['general']);

        self::assertSame('economy', $classifier->classify(
            self::item('faz', 'Inflation sinkt auf zwei Prozent', 'https://x/22'), $de));
        self::assertSame('economy', $classifier->classify(
            self::item('aljazeera', 'Unemployment rises as recession deepens', 'https://x/23'), $en));
    }

    public function testDemocracyClassification(): void
    {
        $classifier = new Classifier();
        $de = self::source('zeit', 'dach', ['de'], 0.0, ['general']);
        $en = self::source('aljazeera', 'global-south', ['en'], 0.0, ['general']);

        self::assertSame('democracy', $classifier->classify(
            self::item('zeit', 'Wahlen in Polen: Opposition liegt vorn', 'https://x/24'), $de));
        self::assertSame('democracy', $classifier->classify(
            self::item('aljazeera', 'Constitutional court curbs emergency powers', 'https://x/25'), $en));
    }

    public function testMigrationClassification(): void
    {
        $classifier = new Classifier();
        $de = self::source('taz', 'dach', ['de'], -2.0, ['general']);
        $en = self::source('aljazeera', 'global-south', ['en'], 0.0, ['general']);

        self::assertSame('migration', $classifier->classify(
            self::item('taz', 'Abschiebung nach Afghanistan ausgesetzt', 'https://x/26'), $de));
        self::assertSame('migration', $classifier->classify(
            self::item('aljazeera', 'EU states split over asylum reform', 'https://x/27'), $en));
    }

    public function testScienceClassification(): void
    {
        $classifier = new Classifier();
        $de = self::source('spiegel', 'dach', ['de'], 0.0, ['general']);
        $en = self::source('aljazeera', 'global-south', ['en'], 0.0, ['general']);

        self::assertSame('science', $classifier->classify(
            self::item('spiegel', 'Teleskop entdeckt ferne Galaxie', 'https://x/28'), $de));
        self::assertSame('science', $classifier->classify(
            self::item('aljazeera', 'Quantum experiment breaks entanglement record', 'https://x/29'), $en));
    }

    public function testClimateResearchStaysClimate(): void
    {
        // "Klimaforschung" hits climate and science ("forschung") once
        // each — the tie must fall to climate via TOPIC_ORDER.
        $classifier = new Classifier();
        $de = self::source('spiegel', 'dach', ['de'], 0.0, ['general']);
        $en = self::source('aljazeera', 'global-south', ['en'], 0.0, ['general']);

        self::assertSame('climate', $classifier->classify(
            self::item('spiegel', 'Klimaforschung warnt vor Kipppunkten', 'https://x/30'), $de));
        self::assertSame('climate', $classifier->classify(
            self::item('aljazeera', 'Climate research warns of tipping points', 'https://x/31'), $en));
    }

    public function testEuropeanIntegrationIsNotMigration(): void
    {
        // "Integration" (EU integration, tech integration) is deliberately
        // not a migration keyword — its common usage is broader than the
        // topic (classification.md §3).
        $source = self::source('zeit', 'dach', ['de'], 0.0, ['general']);
        $item = self::item('zeit', 'Debatte über die europäische Integration', 'https://x/32');

        self::assertNull((new Classifier())->classify($item, $source));
    }

    public function testCompactModeGivesEveryTopicAFairQuota(): void
    {
        // Three generalist DACH sources across seven topics plus one
        // science specialist: 7 × 3 = 21 candidates saturate the total
        // cap, so without quotas the earlier TOPIC_ORDER entries would
        // exhaust it and science — the last topic — never appears.
        $registry = new Registry([
            self::source('a', 'dach', ['de'], -2.0, ['general']),
            self::source('b', 'dach', ['de'], 0.0, ['general']),
            self::source('c', 'dach', ['de'], 2.0, ['general']),
            self::source('spec', 'dach', ['de'], -1.0, ['science']),
        ]);

        $words = ['Klimakrise', 'Krieg', 'Datenschutz', 'Demenz', 'Inflation', 'Wahlen', 'Abschiebung'];
        $items = [];
        foreach (['a', 'b', 'c'] as $id) {
            foreach ($words as $n => $word) {
                for ($i = 0; $i < 2; ++$i) {
                    // titles share only the topic keyword across sources, so
                    // they stay distinct stories instead of clustering
                    $items[] = self::item($id, "{$word} {$id}Bericht{$i} {$id}Ereignis{$i} {$id}Region{$i} {$id}Detail{$i}", "https://x/{$id}/{$n}/{$i}");
                }
            }
        }
        $items[] = self::item('spec', 'Neue Studie im Fachjournal erschienen', 'https://x/spec/1');

        $edition = (new Builder())->build($registry, $items, new \DateTimeImmutable(), Mode::Compact);

        $topics = array_map(fn ($s) => $s->topic, $edition->sections);
        self::assertContains('science', $topics, 'fair quota must reserve room for every non-empty topic');
        self::assertSame(Builder::MAX_ITEMS_TOTAL, $edition->total(), 'fixture must saturate the cap, or the squeeze-out premise is gone');
        foreach ($edition->sections as $section) {
            self::assertLessThanOrEqual(Builder::MAX_ITEMS_PER_TOPIC, count($section->articles));
        }
    }

    public function testDuplicateSourceIdsAreReported(): void
    {
        $registry = new Registry([
            self::source('dup', 'dach', ['de'], 0.0, ['general']),
            self::source('dup', 'dach', ['de'], 1.0, ['general']),
        ]);

        $problems = $registry->validate();
        self::assertNotEmpty(array_filter($problems, fn ($p) => str_contains($p, 'duplicate id')));
    }

    public function testEvidenceEntriesRequireUrlAndNote(): void
    {
        $source = new Source(
            id: 'ev', name: 'ev', country: 'DE', languages: ['de'],
            type: 'online', ownership: 'private', publisher: 'ev', funding: [],
            perspective: 'dach', topics: ['general'], homepage: '', feeds: ['https://x/feed'],
            rating: new Rating(0.0, 0.0, 0.0, 'nonpartisan', 4, 'high', 'none', 'high',
                evidence: [['url' => '', 'note' => 'no url here']]),
        );

        $problems = (new Registry([$source]))->validate();
        self::assertNotEmpty(array_filter($problems, fn ($p) => str_contains($p, 'evidence[0] has no url')));
    }

    public function testNearDuplicateHeadlinesClusterIntoOneStory(): void
    {
        $registry = new Registry([
            self::source('a', 'dach', ['de'], -2.0, ['general']),
            self::source('b', 'dach', ['de'], 2.0, ['general']),
        ]);
        // Explicit timestamps: with three helper items created "now",
        // microseconds decide the newest-first order and the leader
        // assertion below becomes a coin flip.
        $now = new \DateTimeImmutable();
        $items = [
            new Item('a', 'Nato-Gipfel beschließt neue Klimaschutz-Ziele für Streitkräfte', 'https://x/a/1', '', $now),
            new Item('b', 'Nato-Gipfel beschließt neue Klimaschutz-Ziele für die Streitkräfte', 'https://x/b/1', '', $now->modify('-30 minutes')),
            new Item('b', 'Dürre bedroht Ernte in Südeuropa', 'https://x/b/2', '', $now->modify('-1 hour')),
        ];

        $edition = (new Builder())->build($registry, $items, $now, Mode::Full);

        self::assertSame(2, $edition->total(), 'near-identical headlines must collapse into one story');
        $clustered = null;
        foreach ($edition->sections as $section) {
            foreach ($section->articles as $article) {
                if ($article->alsoCoveredBy !== []) {
                    $clustered = $article;
                }
            }
        }
        self::assertNotNull($clustered, 'the collapsed telling must survive as a cluster member');
        self::assertSame('a', $clustered->source->id, 'the freshest telling leads the cluster');
        self::assertSame(['b'], array_map(fn (Article $t) => $t->source->id, $clustered->alsoCoveredBy));
    }

    public function testSecondTellingFromTheSameSourceIsDroppedNotAttached(): void
    {
        $registry = new Registry([
            self::source('a', 'dach', ['de'], -2.0, ['general']),
        ]);
        $items = [
            self::item('a', 'Nato-Gipfel beschließt neue Klimaschutz-Ziele für Streitkräfte', 'https://x/a/1'),
            self::item('a', 'Nato-Gipfel beschließt neue Klimaschutz-Ziele für die Streitkräfte', 'https://x/a/2'),
        ];

        $byTopic = (new Builder())->classifyFresh($registry, $items, new \DateTimeImmutable());

        $articles = array_merge(...array_values($byTopic));
        self::assertCount(1, $articles);
        self::assertSame([], $articles[0]->alsoCoveredBy, 'a source cannot duel itself');
    }

    public function testFindFreshResolvesClusterMembersAsTheirOwnSource(): void
    {
        $registry = new Registry([
            self::source('a', 'dach', ['de'], -2.0, ['general']),
            self::source('b', 'dach', ['de'], 2.0, ['general']),
        ]);
        // Explicit timestamps: 'a' must lead the cluster so 'b' is the
        // member being resolved (same trap as the cluster-leader test —
        // item() stamps "now", and microseconds would decide the leader).
        $now = new \DateTimeImmutable();
        $items = [
            new Item('a', 'Nato-Gipfel beschließt neue Klimaschutz-Ziele für Streitkräfte', 'https://x/a/1', '', $now),
            new Item('b', 'Nato-Gipfel beschließt neue Klimaschutz-Ziele für die Streitkräfte', 'https://x/b/1', '', $now->modify('-30 minutes')),
        ];

        $found = (new Builder())->findFresh($registry, $items, $now, 'https://x/b/1');

        self::assertNotNull($found, 'a cluster member link must resolve');
        self::assertSame('b', $found->source->id, 'reads attribute to the source actually read');
        self::assertSame([], $found->alsoCoveredBy);
    }

    public function testMissingPerspectivesAreMeasuredAgainstTheCandidates(): void
    {
        $dachLeft = self::source('taz', 'dach', ['de'], -2.0, ['general']);
        $dachRight = self::source('faz', 'dach', ['de'], 2.0, ['general']);
        $south = self::source('mongabay', 'global-south', ['en'], 0.0, ['general']);

        $articles = [
            new Article(self::item('taz', 'x', 'https://x/1'), $dachLeft, 'climate'),
            new Article(self::item('faz', 'y', 'https://x/2'), $dachRight, 'climate'),
        ];
        $diversity = new \Meridian\Spectrum\Diversity();

        // A perspective with candidates that the selection squeezed out is a gap.
        $squeezed = new Section('climate', $articles, $diversity, ['dach', 'global-south']);
        self::assertSame(['global-south'], $squeezed->missingPerspectives());

        // The possible, not the ideal: no candidate → no blind spot.
        $silent = new Section('climate', $articles, $diversity, ['dach']);
        self::assertSame([], $silent->missingPerspectives());

        // A cluster member's telling is visible on the card — not a gap.
        $clustered = new Section('climate', [
            $articles[0],
            new Article(self::item('faz', 'y', 'https://x/2'), $dachRight, 'climate', [
                new Article(self::item('mongabay', 'z', 'https://x/3'), $south, 'climate'),
            ]),
        ], $diversity, ['dach', 'global-south']);
        self::assertSame([], $clustered->missingPerspectives());

        // Under-two guard and unknown candidate accounting report nothing.
        $single = new Section('climate', [$articles[0]], $diversity, ['dach', 'global-south']);
        self::assertSame([], $single->missingPerspectives());
        self::assertSame([], (new Section('climate', $articles, $diversity))->missingPerspectives());
    }

    public function testCompactSectionsReportNoGapForSilentPerspectives(): void
    {
        // global-south sits in the dataset but published nothing on the
        // topic — that is not a blind spot of the edition.
        $registry = new Registry([
            self::source('taz', 'dach', ['de'], -2.0, ['general']),
            self::source('faz', 'dach', ['de'], 2.0, ['general']),
            self::source('mongabay', 'global-south', ['en'], 0.0, ['general']),
        ]);
        $items = [
            self::item('taz', 'Klimakrise verschärft Dürre in Europa', 'https://x/1'),
            self::item('faz', 'Emissionshandel vor der Reform', 'https://x/2'),
        ];

        $edition = (new Builder())->build($registry, $items, new \DateTimeImmutable(), Mode::Compact);

        self::assertNotSame([], $edition->sections);
        foreach ($edition->sections as $section) {
            self::assertSame([], $section->missingPerspectives());
        }
    }

    public function testSectionAxisGridCountsPrimariesOnBothAxes(): void
    {
        $left = new Article(self::item('a', 'x', 'https://x/1'), self::source('a', 'dach', ['de'], -2.0, ['general']), 'climate');
        $centre = new Article(self::item('b', 'y', 'https://x/2'), self::source('b', 'dach', ['de'], 0.0, ['general']), 'climate');

        $grid = (new Section('climate', [$left, $centre], new \Meridian\Spectrum\Diversity()))->axisGrid();

        self::assertSame(1, $grid[0][2], 'economic band 0 × cultural band 2');
        self::assertSame(1, $grid[2][2], 'economic band 2 × cultural band 2');
        self::assertSame(0, $grid[4][4]);
    }

    public function testSpecialistItemsGetTheLongFreshnessWindow(): void
    {
        // Specialists publish slowly — a five-day-old post must still
        // surface, while the same age drops a generalist item.
        $registry = new Registry([
            self::source('spec', 'dach', ['de'], -1.0, ['accessibility']),
            self::source('gen', 'dach', ['de'], 0.0, ['general']),
        ]);
        $old = new \DateTimeImmutable('-5 days');
        $items = [
            new Item('spec', 'Neues Angebot für Assistenz', 'https://x/s1', '', $old),
            new Item('gen', 'Klimakrise Bericht', 'https://x/g1', '', $old),
        ];

        $byTopic = (new Builder())->classifyFresh($registry, $items, new \DateTimeImmutable());

        self::assertArrayHasKey('accessibility', $byTopic);
        self::assertArrayNotHasKey('climate', $byTopic, 'generalist items keep the 48 h window');
    }

    public function testSectionNamesItsMissingEconomicSides(): void
    {
        $left = new Article(self::item('a', 'x', 'https://x/1'), self::source('a', 'dach', ['de'], -2.0, ['general']), 'climate');
        $centre = new Article(self::item('b', 'y', 'https://x/2'), self::source('b', 'dach', ['de'], 0.0, ['general']), 'climate');

        $section = new Section('climate', [$left, $centre], new \Meridian\Spectrum\Diversity());
        self::assertSame(['right'], $section->missingEconomicSides());

        $single = new Section('climate', [$left], new \Meridian\Spectrum\Diversity());
        self::assertSame([], $single->missingEconomicSides(), 'one article is no basis for a blind-spot claim');
    }

    public function testFullModeShowsEverythingClassified(): void
    {
        $registry = new Registry([
            self::source('left-de', 'dach', ['de'], -2.0, ['general']),
        ]);
        $items = [];
        for ($i = 0; $i < 20; ++$i) {
            $items[] = self::item('left-de', "Klimakrise Update: Ereignis{$i} Region{$i} Detail{$i}", "https://x/{$i}");
        }

        $edition = (new Builder())->build($registry, $items, new \DateTimeImmutable(), Mode::Full);

        self::assertSame(20, $edition->total(), 'full mode must not cap');
    }
}
