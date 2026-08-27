<?php

declare(strict_types=1);

namespace Meridian\Edition;

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
     *               sections: list<array{topic: string, articles: list<array<string, string>>}>}|null
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
}
