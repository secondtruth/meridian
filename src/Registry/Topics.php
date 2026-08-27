<?php

declare(strict_types=1);

namespace Meridian\Registry;

use Symfony\Component\Yaml\Yaml;

/**
 * The focus-topic vocabulary: every topic is a curated selection of IPTC
 * Media Topics subtrees, loaded from data/topics.yaml and specified in
 * docs/classification.md §6. Active topics must stay in sync with
 * Registry::TOPICS; proposed ones are agreed direction not yet wired
 * into the classifier.
 */
final class Topics
{
    public const STATUSES = ['active', 'proposed'];

    private const CODE_PATTERN = '/\Amedtop:[0-9]{8}\z/D';

    /** @var array<string, array{status: string, iptc: list<string>}> keyed by topic id */
    private array $topics = [];

    /** @var list<string> ids that appeared more than once while loading */
    private array $duplicateIds = [];

    /** @param list<array<string, mixed>> $entries */
    public function __construct(array $entries)
    {
        foreach ($entries as $entry) {
            $id = (string) ($entry['id'] ?? '');
            if (isset($this->topics[$id])) {
                $this->duplicateIds[] = $id;
            }
            $codes = is_array($entry['iptc'] ?? null) ? $entry['iptc'] : [];
            $this->topics[$id] = [
                'status' => (string) ($entry['status'] ?? ''),
                'iptc' => array_values(array_map(strval(...), $codes)),
            ];
        }
    }

    public static function load(string $file): self
    {
        $entries = Yaml::parseFile($file);
        if (!is_array($entries)) {
            throw new \RuntimeException("{$file}: expected a YAML list of topics");
        }

        return new self($entries);
    }

    public function count(): int
    {
        return count($this->topics);
    }

    /** @return list<string> ids of active topics, in file order */
    public function active(): array
    {
        $ids = [];
        foreach ($this->topics as $id => $topic) {
            if ($topic['status'] === 'active') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return list<string> IPTC concept codes of a topic, empty for unknown ids */
    public function iptc(string $id): array
    {
        return $this->topics[$id]['iptc'] ?? [];
    }

    /**
     * Checks the vocabulary against the dataset rules, including that the
     * active topics are exactly Registry::TOPICS minus "general".
     *
     * @return list<string> one message per violation, empty when valid
     */
    public function validate(): array
    {
        $problems = [];
        $report = function (string $id, string $message) use (&$problems): void {
            $problems[] = "{$id}: {$message}";
        };

        foreach ($this->duplicateIds as $id) {
            $report($id, 'duplicate id — a later entry silently replaced an earlier one');
        }

        $codeOwners = [];
        foreach ($this->topics as $id => $topic) {
            if ($id === '') {
                $problems[] = 'topic with empty id';
                continue;
            }
            if (!in_array($topic['status'], self::STATUSES, true)) {
                $report($id, "invalid status \"{$topic['status']}\"");
            }
            if ($topic['iptc'] === []) {
                $report($id, 'no IPTC concepts assigned');
            }
            foreach ($topic['iptc'] as $code) {
                if (preg_match(self::CODE_PATTERN, $code) !== 1) {
                    $report($id, "IPTC code must look like medtop:12345678, got \"{$code}\"");
                    continue;
                }
                // Only literal duplicates are conflicts; one topic's code
                // nesting inside another's subtree is legitimate and
                // resolved by the precedence rule in classification.md §6.
                if (isset($codeOwners[$code])) {
                    $report($id, "{$code} is already claimed by {$codeOwners[$code]}");
                } else {
                    $codeOwners[$code] = $id;
                }
            }
        }

        $focusTopics = array_values(array_diff(Registry::TOPICS, ['general']));
        foreach ($focusTopics as $topic) {
            if (!isset($this->topics[$topic])) {
                $report($topic, 'focus topic from Registry::TOPICS is missing from the vocabulary');
            } elseif ($this->topics[$topic]['status'] !== 'active') {
                $report($topic, 'listed in Registry::TOPICS but not active in the vocabulary');
            }
        }
        foreach ($this->active() as $id) {
            if (!in_array($id, $focusTopics, true)) {
                $report($id, 'active but not in Registry::TOPICS — activate it in code or set status: proposed');
            }
        }

        return $problems;
    }
}
