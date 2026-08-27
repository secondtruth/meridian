<?php

declare(strict_types=1);

namespace Meridian\Spectrum;

use Meridian\Account\ArticleRef;
use Meridian\Edition\Classifier;
use Meridian\Registry\Registry;

/**
 * Turns a reader's own history into the same kind of spectrum report
 * Meridian applies to its sources.
 *
 * The reference for perspective and spectrum is how the dataset itself
 * is composed — a share far above the offer means the reader narrows
 * what Meridian already balanced for them. Topics carry no reference:
 * the four focus topics are not published in equal volume, so a share
 * there is only meaningful as "read something / read nothing".
 */
final class Balance
{
    /** @param list<ArticleRef> $reads */
    public static function of(array $reads, Registry $registry, int $days): BalanceReport
    {
        $perspectiveReads = [];
        $economicReads = [];
        $culturalReads = [];
        $topicReads = [];
        $sourceReads = [];
        $reliabilities = [];
        $readSources = [];

        foreach ($reads as $read) {
            $topicReads[$read->topic] = ($topicReads[$read->topic] ?? 0) + 1;
            $sourceReads[$read->sourceId] = ($sourceReads[$read->sourceId] ?? 0) + 1;

            $source = $registry->get($read->sourceId);
            if ($source === null) {
                continue; // source has since left the dataset
            }
            $readSources[] = $source;
            $perspectiveReads[$source->perspective] = ($perspectiveReads[$source->perspective] ?? 0) + 1;
            $economic = (string) Band::of($source->rating->economic)->value;
            $cultural = (string) Band::of($source->rating->cultural)->value;
            $economicReads[$economic] = ($economicReads[$economic] ?? 0) + 1;
            $culturalReads[$cultural] = ($culturalReads[$cultural] ?? 0) + 1;
            $reliabilities[] = $source->rating->reliability;
        }

        $perspectiveOffer = [];
        $economicOffer = [];
        $culturalOffer = [];
        foreach ($registry->all() as $source) {
            $perspectiveOffer[$source->perspective] = ($perspectiveOffer[$source->perspective] ?? 0) + 1;
            $economic = (string) Band::of($source->rating->economic)->value;
            $cultural = (string) Band::of($source->rating->cultural)->value;
            $economicOffer[$economic] = ($economicOffer[$economic] ?? 0) + 1;
            $culturalOffer[$cultural] = ($culturalOffer[$cultural] ?? 0) + 1;
        }

        $bands = array_map(static fn (Band $band): string => (string) $band->value, Band::cases());

        arsort($sourceReads);
        $sources = [];
        foreach ($sourceReads as $id => $count) {
            $sources[] = ['id' => (string) $id, 'source' => $registry->get((string) $id), 'count' => $count];
        }

        return new BalanceReport(
            days: $days,
            total: count($reads),
            perspectives: Distribution::build('perspective', Registry::PERSPECTIVES, $perspectiveReads, $perspectiveOffer),
            economic: Distribution::build('economic', $bands, $economicReads, $economicOffer),
            cultural: Distribution::build('cultural', $bands, $culturalReads, $culturalOffer),
            topics: Distribution::build('topic', Classifier::TOPIC_ORDER, $topicReads),
            sources: $sources,
            axisGrid: AxisGrid::count($readSources),
            axisGridOffer: AxisGrid::count($registry->all()),
            averageReliability: $reliabilities === []
                ? null
                : array_sum($reliabilities) / count($reliabilities),
        );
    }
}
