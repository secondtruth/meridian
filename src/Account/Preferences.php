<?php

declare(strict_types=1);

namespace Meridian\Account;

use Meridian\Edition\Classifier;
use Meridian\Edition\Mode;
use Meridian\I18n\Translator;

/**
 * What a signed-in reader may configure.
 *
 * Deliberately absent: any filter on perspective, party family or
 * spectrum position. Muting is limited to the four focus topics —
 * ratings inform balance, they never exclude (see AGENTS.md).
 */
final readonly class Preferences
{
    public const RETENTION_CHOICES = [30, 90, 365, 0];

    /** @param list<string> $mutedTopics */
    public function __construct(
        public string $locale = Translator::DEFAULT,
        public Mode $mode = Mode::Compact,
        public array $mutedTopics = [],
        public bool $dailyLimit = true,
        public bool $trackReading = true,
        public int $retentionDays = 90,
    ) {
    }

    /**
     * Builds preferences from untrusted input, dropping anything invalid.
     * Muting every topic would leave an empty edition, so the last
     * remaining topic cannot be muted.
     *
     * @param array<string, mixed> $input
     */
    public static function fromInput(array $input): self
    {
        $muted = array_values(array_intersect(
            Classifier::TOPIC_ORDER,
            array_map(strval(...), (array) ($input['muted_topics'] ?? [])),
        ));
        if (count($muted) >= count(Classifier::TOPIC_ORDER)) {
            array_pop($muted);
        }

        $retention = (int) ($input['retention_days'] ?? 90);

        return new self(
            locale: Translator::resolveLocale(isset($input['locale']) ? (string) $input['locale'] : null),
            mode: Mode::fromQuery(isset($input['mode']) ? (string) $input['mode'] : null),
            mutedTopics: $muted,
            dailyLimit: (bool) ($input['daily_limit'] ?? false),
            trackReading: (bool) ($input['track_reading'] ?? false),
            retentionDays: in_array($retention, self::RETENTION_CHOICES, true) ? $retention : 90,
        );
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $muted = array_values(array_filter(explode(',', (string) $row['muted_topics'])));

        return new self(
            locale: Translator::resolveLocale((string) $row['locale']),
            mode: Mode::fromQuery((string) $row['mode']),
            mutedTopics: array_values(array_intersect(Classifier::TOPIC_ORDER, $muted)),
            dailyLimit: (bool) $row['daily_limit'],
            trackReading: (bool) $row['track_reading'],
            retentionDays: (int) $row['retention_days'],
        );
    }

    /** @return array<string, string|int> */
    public function toRow(): array
    {
        return [
            'locale' => $this->locale,
            'mode' => $this->mode->value,
            'muted_topics' => implode(',', $this->mutedTopics),
            'daily_limit' => (int) $this->dailyLimit,
            'track_reading' => (int) $this->trackReading,
            'retention_days' => $this->retentionDays,
        ];
    }

    public function mutes(string $topic): bool
    {
        return in_array($topic, $this->mutedTopics, true);
    }

    /** @return list<string> the topics that still appear in the edition */
    public function activeTopics(): array
    {
        return array_values(array_diff(Classifier::TOPIC_ORDER, $this->mutedTopics));
    }

    public function withLocale(string $locale): self
    {
        return new self(
            $locale, $this->mode, $this->mutedTopics,
            $this->dailyLimit, $this->trackReading, $this->retentionDays,
        );
    }

    public function withMode(Mode $mode): self
    {
        return new self(
            $this->locale, $mode, $this->mutedTopics,
            $this->dailyLimit, $this->trackReading, $this->retentionDays,
        );
    }
}
