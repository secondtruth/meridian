<?php

declare(strict_types=1);

namespace Meridian\Edition;

use Meridian\Feed\Item;
use Meridian\Registry\Registry;

/**
 * Date-addressable snapshots of the compact edition (rating-system.md
 * §7): one JSON file per day, written by the daily cron after the
 * fetch, rendered read-only by /archive. Re-running a day overwrites
 * its snapshot — the command is idempotent.
 */
final class Archive
{
    private const DATE_PATTERN = '/\A\d{4}-\d{2}-\d{2}\z/D';

    public function __construct(private readonly string $dir)
    {
    }

    /** @return string the date key the edition was stored under */
    public function save(Edition $edition): string
    {
        $date = $edition->date->format('Y-m-d');
        $sections = [];
        foreach ($edition->sections as $section) {
            $articles = [];
            foreach ($section->articles as $article) {
                $tellings = [];
                foreach ($article->alsoCoveredBy as $telling) {
                    $tellings[] = [
                        'source_id' => $telling->source->id,
                        'source_name' => $telling->source->name,
                        'title' => $telling->item->title,
                        'link' => $telling->item->link,
                        'published' => $telling->item->published->format(\DateTimeInterface::ATOM),
                    ];
                }
                $articles[] = [
                    'source_id' => $article->source->id,
                    'source_name' => $article->source->name,
                    'title' => $article->item->title,
                    'link' => $article->item->link,
                    'summary' => $article->item->summary,
                    'published' => $article->item->published->format(\DateTimeInterface::ATOM),
                    'also_covered_by' => $tellings,
                ];
            }
            $sections[] = ['topic' => $section->topic, 'articles' => $articles];
        }

        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
        $json = json_encode(
            [
                'date' => $date,
                'generated_at' => $edition->date->format(\DateTimeInterface::ATOM),
                'sections' => $sections,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        file_put_contents("{$this->dir}/{$date}.json", $json . "\n");

        return $date;
    }

    /** @return list<string> archived dates, newest first */
    public function dates(): array
    {
        $dates = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $date = basename($file, '.json');
            if (preg_match(self::DATE_PATTERN, $date) === 1) {
                $dates[] = $date;
            }
        }
        rsort($dates);

        return $dates;
    }

    /**
     * @return array{date: string, generated_at: string,
     *               sections: list<array{topic: string, articles: list<array<string, mixed>>}>}|null
     */
    public function load(string $date): ?array
    {
        if (preg_match(self::DATE_PATTERN, $date) !== 1) {
            return null;
        }
        $file = "{$this->dir}/{$date}.json";
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    /**
     * A frozen day rebuilt for display. Articles whose source is still in
     * the dataset come back as {@see Article}s carrying the *current*
     * rating (the page labels it as such); articles from since-removed
     * sources keep only their stored text. Cluster members from removed
     * sources are dropped silently — a telling without a rating is not a
     * telling in this product.
     *
     * @return list<ArchivedSection>|null null when no such day is archived
     */
    public function restore(string $date, Registry $registry): ?array
    {
        $data = $this->load($date);
        if ($data === null) {
            return null;
        }

        $sections = [];
        foreach ($data['sections'] as $section) {
            $entries = [];
            foreach ($section['articles'] as $stored) {
                $source = $registry->get($stored['source_id']);
                $tellings = [];
                foreach ($stored['also_covered_by'] ?? [] as $member) {
                    $memberSource = $registry->get($member['source_id']);
                    if ($memberSource === null) {
                        continue;
                    }
                    $tellings[] = new Article(
                        self::item($member['source_id'], $member['title'], $member['link'], '', $member['published']),
                        $memberSource,
                        $section['topic'],
                    );
                }
                $entries[] = new ArchivedEntry(
                    sourceName: $stored['source_name'],
                    title: $stored['title'],
                    link: $stored['link'],
                    summary: $stored['summary'],
                    article: $source === null ? null : new Article(
                        self::item($stored['source_id'], $stored['title'], $stored['link'], $stored['summary'], $stored['published']),
                        $source,
                        $section['topic'],
                        $tellings,
                    ),
                );
            }
            $sections[] = new ArchivedSection($section['topic'], $entries);
        }

        return $sections;
    }

    private static function item(string $sourceId, string $title, string $link, string $summary, string $published): Item
    {
        return new Item(
            sourceId: $sourceId,
            title: $title,
            link: $link,
            summary: $summary,
            published: new \DateTimeImmutable($published),
        );
    }
}
