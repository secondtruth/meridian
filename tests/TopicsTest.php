<?php

declare(strict_types=1);

namespace Meridian\Tests;

use Meridian\Registry\Topics;
use PHPUnit\Framework\TestCase;

final class TopicsTest extends TestCase
{
    /** @return list<array<string, mixed>> a minimal valid vocabulary matching Registry::TOPICS */
    private static function validEntries(): array
    {
        return [
            ['id' => 'climate', 'status' => 'active', 'iptc' => ['medtop:06000000']],
            ['id' => 'peace', 'status' => 'active', 'iptc' => ['medtop:16000000']],
            ['id' => 'digital-rights', 'status' => 'active', 'iptc' => ['medtop:20001300']],
            ['id' => 'accessibility', 'status' => 'active', 'iptc' => ['medtop:20000791']],
            ['id' => 'health', 'status' => 'active', 'iptc' => ['medtop:07000000']],
            ['id' => 'economy', 'status' => 'active', 'iptc' => ['medtop:09000000']],
            ['id' => 'democracy', 'status' => 'active', 'iptc' => ['medtop:20000654']],
            ['id' => 'migration', 'status' => 'active', 'iptc' => ['medtop:20000771']],
            ['id' => 'science', 'status' => 'active', 'iptc' => ['medtop:13000000']],
        ];
    }

    public function testShippedVocabularyIsValidAndMatchesRegistry(): void
    {
        $topics = Topics::load(__DIR__ . '/../data/topics.yaml');

        self::assertSame([], $topics->validate());
        self::assertSame(
            [
                'climate', 'peace', 'digital-rights', 'accessibility',
                'health', 'economy', 'democracy', 'migration', 'science',
            ],
            $topics->active(),
        );
    }

    public function testMinimalVocabularyIsValid(): void
    {
        self::assertSame([], (new Topics(self::validEntries()))->validate());
    }

    public function testMalformedCodeIsReported(): void
    {
        $entries = self::validEntries();
        $entries[0]['iptc'] = ['06000000'];

        $problems = (new Topics($entries))->validate();

        self::assertCount(1, $problems);
        self::assertStringContainsString('medtop:12345678', $problems[0]);
    }

    public function testLiteralDuplicateCodeAcrossTopicsIsReported(): void
    {
        $entries = self::validEntries();
        $entries[1]['iptc'][] = 'medtop:06000000';

        $problems = (new Topics($entries))->validate();

        self::assertCount(1, $problems);
        self::assertStringContainsString('already claimed by climate', $problems[0]);
    }

    public function testUnknownStatusIsReported(): void
    {
        $entries = self::validEntries();
        $entries[0]['status'] = 'draft';

        $problems = (new Topics($entries))->validate();

        // Invalid status, and "climate" no longer counts as active.
        self::assertCount(2, $problems);
        self::assertStringContainsString('invalid status "draft"', $problems[0]);
    }

    public function testRegistryTopicMustBeActiveInVocabulary(): void
    {
        $entries = self::validEntries();
        $entries[0]['status'] = 'proposed';

        $problems = (new Topics($entries))->validate();

        self::assertSame(['climate: listed in Registry::TOPICS but not active in the vocabulary'], $problems);
    }

    public function testMissingRegistryTopicIsReported(): void
    {
        $entries = self::validEntries();
        unset($entries[3]);

        $problems = (new Topics(array_values($entries)))->validate();

        self::assertSame(['accessibility: focus topic from Registry::TOPICS is missing from the vocabulary'], $problems);
    }

    public function testActiveTopicUnknownToRegistryIsReported(): void
    {
        $entries = self::validEntries();
        $entries[] = ['id' => 'culture', 'status' => 'active', 'iptc' => ['medtop:01000000']];

        $problems = (new Topics($entries))->validate();

        self::assertSame(['culture: active but not in Registry::TOPICS — activate it in code or set status: proposed'], $problems);
    }

    public function testProposedTopicIsAllowedAndNotActive(): void
    {
        $entries = self::validEntries();
        $entries[] = ['id' => 'culture', 'status' => 'proposed', 'iptc' => ['medtop:01000000']];
        $topics = new Topics($entries);

        self::assertSame([], $topics->validate());
        self::assertNotContains('culture', $topics->active());
        self::assertSame(['medtop:01000000'], $topics->iptc('culture'));
    }
}
