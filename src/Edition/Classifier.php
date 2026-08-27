<?php

declare(strict_types=1);

namespace Meridian\Edition;

use Meridian\Feed\Item;
use Meridian\Registry\Source;

/**
 * Assigns feed items to the focus topics. Keyword lists are split by
 * language: German sources match via substring (compounds like
 * "Atomwaffen" contain but do not start with "waffen"), English sources
 * match token-based to avoid false hits like "warning" containing "war".
 */
final class Classifier
{
    public const TOPIC_ORDER = [
        'climate', 'peace', 'digital-rights', 'accessibility',
        'health', 'economy', 'democracy', 'migration', 'science',
    ];

    public const KEYWORDS_DE = [
        'climate' => [
            'klimawandel', 'klimakrise', 'klimaschutz', 'klimapolitik',
            'klimaforschung', 'erderwärmung', 'emissionen', 'co2',
            'fossile', 'erneuerbare', 'biodiversität', 'artenschutz',
            'abholzung', 'dürre', 'hochwasser', 'flutkatastrophe',
            'energiewende', 'ipcc', 'treibhaus', 'umweltschutz',
        ],
        'peace' => [
            'krieg', 'waffen', 'rüstung', 'militär', 'frieden',
            'waffenruhe', 'waffenstillstand', 'abrüstung', 'nato',
            'drohne', 'sanktion', 'bundeswehr', 'gefecht',
        ],
        'digital-rights' => [
            'datenschutz', 'überwachung', 'dsgvo', 'zensur',
            'verschlüsselung', 'chatkontrolle', 'open source',
            'künstliche intelligenz', 'plattform', 'algorithm',
            'netzneutralität', 'informationsfreiheit', 'digitale gewalt',
            'staatstrojaner', 'pressefreiheit',
        ],
        'accessibility' => [
            'barrierefrei', 'inklusion', 'behinderung', 'gebärdensprache',
            'leichte sprache', 'screenreader', 'ableismus', 'teilhabe',
        ],
        'health' => [
            'gesundheit', 'krankenhaus', 'krankenkasse', 'klinik',
            'patient', 'impfung', 'pandemie', 'epidemie', 'medikament',
            'therapie', 'psychisch', 'demenz', 'pflegeheim',
        ],
        'economy' => [
            'inflation', 'konjunktur', 'rezession', 'arbeitsmarkt',
            'arbeitslos', 'gewerkschaft', 'streik', 'tarif',
            'mindestlohn', 'bürgergeld', 'rentner', 'rentenreform',
            'rentenversicherung', 'altersvorsorge', 'armut',
            'sozialstaat', 'haushalt', 'insolvenz', 'ezb', 'zölle',
        ],
        'democracy' => [
            'demokratie', 'rechtsstaat', 'verfassung', 'grundgesetz',
            'grundrechte', 'parlament', 'bundestag', 'korruption',
            'justiz', 'gerichtshof', 'wahlen', 'wahlkampf', 'wahlrecht',
            'referendum', 'opposition',
        ],
        'migration' => [
            'migration', 'migranten', 'flüchtling', 'geflüchtete',
            'asyl', 'asylbewerber', 'asylverfahren', 'abschiebung',
            'einwanderung', 'zuwanderung', 'einbürgerung',
            'seenotrettung', 'frontex', 'bleiberecht',
        ],
        'science' => [
            'forschung', 'wissenschaft', 'experiment', 'raumfahrt',
            'weltraum', 'teleskop', 'astronom', 'physik', 'quanten',
            'gentechnik', 'nobelpreis', 'archäolog',
        ],
    ];

    public const KEYWORDS_EN = [
        'climate' => [
            'climate', 'global warming', 'emission', 'co2', 'fossil',
            'renewable', 'biodiversity', 'deforestation', 'drought',
            'flood', 'ipcc', 'greenhouse', 'wildfire', 'net zero',
        ],
        'peace' => [
            'war', 'weapon', 'arms', 'military', 'peace', 'ceasefire',
            'disarmament', 'nato', 'drone', 'conflict', 'sanction',
            'troops',
        ],
        'digital-rights' => [
            'privacy', 'surveillance', 'gdpr', 'censorship', 'encryption',
            'open source', 'digital services act', 'ai act',
            'artificial intelligence', 'platform', 'algorithm',
            'net neutrality', 'spyware', 'data protection',
            'press freedom',
        ],
        'accessibility' => [
            'accessibility', 'inclusion', 'disability', 'disabled',
            'sign language', 'assistive', 'screen reader', 'ableism',
        ],
        'health' => [
            'health', 'hospital', 'vaccine', 'vaccination', 'pandemic',
            'epidemic', 'disease', 'patient', 'mental health', 'cancer',
            'medicine', 'medical', 'malaria',
        ],
        'economy' => [
            'economy', 'economic', 'inflation', 'recession',
            'unemployment', 'layoff', 'wage', 'wages', 'minimum wage',
            'trade union', 'labour market', 'pension', 'poverty',
            'welfare', 'tariff', 'gdp', 'tax', 'taxes',
        ],
        'democracy' => [
            'democracy', 'democratic', 'election', 'parliament',
            'rule of law', 'corruption', 'judiciary', 'court',
            'constitution', 'referendum', 'voter', 'voting',
            'authoritarian',
        ],
        'migration' => [
            'migration', 'migrant', 'refugee', 'asylum', 'deportation',
            'immigration', 'border', 'resettlement',
        ],
        'science' => [
            'research', 'science', 'scientist', 'scientific',
            'experiment', 'quantum', 'physics', 'astronomy', 'telescope',
            'spacecraft', 'genome', 'gene', 'nasa', 'nobel prize',
        ],
    ];

    /**
     * The topic for an item, or null. Items matching no keyword fall back
     * to the source's single topic if the source is a specialist;
     * generalist noise is dropped — topic specialisation over generalism
     * is a design commitment.
     */
    public function classify(Item $item, Source $source): ?string
    {
        $text = mb_strtolower($item->title . ' ' . $item->summary);
        $german = $source->publishesInGerman();
        $keywords = $german ? self::KEYWORDS_DE : self::KEYWORDS_EN;
        $tokens = $german ? [] : $this->tokenize($text);

        $best = null;
        $bestHits = 0;
        foreach (self::TOPIC_ORDER as $topic) {
            $hits = 0;
            foreach ($keywords[$topic] as $keyword) {
                if ($this->matches($text, $tokens, $keyword, $german)) {
                    ++$hits;
                }
            }
            if ($hits > $bestHits) {
                $best = $topic;
                $bestHits = $hits;
            }
        }

        return $best ?? $source->specialistTopic();
    }

    /**
     * Language-appropriate matching. Phrases: substring. German words:
     * substring, because compounds contain but do not start with the
     * keyword ("Atomwaffen" ⊃ "waffen") — except short keywords, which
     * need letter boundaries ("Senatoren" ⊃ "nato" must not match).
     * English words: exact token when short ("warning" ⊅ "war"), token
     * prefix otherwise ("emissions" ⊐ "emission").
     *
     * @param array<string, true> $tokens
     */
    private function matches(string $text, array $tokens, string $keyword, bool $german): bool
    {
        if (str_contains($keyword, ' ')) {
            return str_contains($text, $keyword);
        }
        if ($german) {
            if (mb_strlen($keyword) <= 4) {
                return preg_match('/(?<!\p{L})' . preg_quote($keyword, '/') . '(?!\p{L})/u', $text) === 1;
            }

            return str_contains($text, $keyword);
        }
        if (mb_strlen($keyword) <= 4) {
            return isset($tokens[$keyword]);
        }
        foreach ($tokens as $token => $_) {
            if (str_starts_with((string) $token, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> */
    private function tokenize(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', $text, flags: PREG_SPLIT_NO_EMPTY) ?: [];

        return array_fill_keys($words, true);
    }
}
