<?php

declare(strict_types=1);

namespace Meridian\Tests;

use Meridian\Account\Database;
use Meridian\Account\Preferences;
use Meridian\Account\Store;
use Meridian\Account\User;
use Meridian\Account\Watchlist;
use Meridian\Edition\Article;
use Meridian\Edition\Classifier;
use Meridian\Edition\Mode;
use Meridian\Feed\Item;
use Meridian\Registry\Rating;
use Meridian\Registry\Source;
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    private Store $store;
    private User $user;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->store = new Store(Database::inMemory());
        $this->now = new \DateTimeImmutable('2026-08-05 12:00:00');
        $this->user = $this->store->accounts->upsert('https://id.example', 'subject-1', 'a@example.org', 'Ada', $this->now);
    }

    private static function article(string $id, string $topic, string $url, string $perspective = 'dach'): Article
    {
        $source = new Source(
            id: $id, name: $id, country: 'DE', languages: ['de'],
            type: 'online', ownership: 'private', publisher: $id, funding: [],
            perspective: $perspective, topics: ['general'], homepage: '', feeds: ['https://x/rss'],
            rating: new Rating(0.0, 0.0, 0.0, 'nonpartisan', 4, 'high', 'none', 'high'),
        );

        return new Article(
            new Item($id, "title of {$url}", $url, '', new \DateTimeImmutable('2026-08-05 10:00:00')),
            $source,
            $topic,
        );
    }

    public function testIdentityIsTheIssuerSubjectPairNotTheMailAddress(): void
    {
        $again = $this->store->accounts->upsert(
            'https://id.example',
            'subject-1',
            'new-address@example.org',
            'Ada L.',
            $this->now->modify('+1 day'),
        );

        self::assertSame($this->user->id, $again->id);
        self::assertSame('new-address@example.org', $again->email);

        $other = $this->store->accounts->upsert('https://other.example', 'subject-1', null, null, $this->now);
        self::assertNotSame($this->user->id, $other->id);
    }

    public function testSessionCookieIsNotStoredInPlainText(): void
    {
        $token = $this->store->sessions->start($this->user->id, $this->now);

        $stored = $this->store->db->pdo()->query('SELECT token_hash FROM sessions')->fetch();
        self::assertNotSame($token, $stored['token_hash']);
        self::assertSame(hash('sha256', $token), $stored['token_hash']);

        $session = $this->store->sessions->lookup($token, $this->now);
        self::assertNotNull($session);
        self::assertSame($this->user->id, $session->userId);
    }

    public function testExpiredAndDestroyedSessionsDoNotResolve(): void
    {
        $token = $this->store->sessions->start($this->user->id, $this->now);

        self::assertNull($this->store->sessions->lookup($token, $this->now->modify('+31 days')));
        self::assertNotNull($this->store->sessions->lookup($token, $this->now));

        $this->store->sessions->destroy($token);
        self::assertNull($this->store->sessions->lookup($token, $this->now));
    }

    public function testCsrfTokenIsComparedInFull(): void
    {
        $token = $this->store->sessions->start($this->user->id, $this->now);
        $session = $this->store->sessions->lookup($token, $this->now);

        self::assertTrue($session->verifyCsrf($session->csrfToken));
        self::assertFalse($session->verifyCsrf(substr($session->csrfToken, 0, -1)));
        self::assertFalse($session->verifyCsrf(null));
        self::assertFalse($session->verifyCsrf(''));
    }

    public function testPreferencesRoundTrip(): void
    {
        $preferences = new Preferences(
            locale: 'en',
            mode: Mode::Full,
            mutedTopics: ['peace'],
            dailyLimit: false,
            trackReading: false,
            retentionDays: 30,
        );
        $this->store->accounts->savePreferences($this->user->id, $preferences);

        $loaded = $this->store->accounts->preferences($this->user->id);
        self::assertSame('en', $loaded->locale);
        self::assertSame(Mode::Full, $loaded->mode);
        self::assertSame(['peace'], $loaded->mutedTopics);
        self::assertFalse($loaded->dailyLimit);
        self::assertFalse($loaded->trackReading);
        self::assertSame(30, $loaded->retentionDays);
    }

    public function testUnknownPreferenceInputFallsBackToDefaults(): void
    {
        $preferences = Preferences::fromInput([
            'locale' => 'klingon',
            'mode' => 'endless-scroll',
            'muted_topics' => ['peace', 'not-a-topic'],
            'retention_days' => '7',
        ]);

        self::assertSame('de', $preferences->locale);
        self::assertSame(Mode::Compact, $preferences->mode);
        self::assertSame(['peace'], $preferences->mutedTopics);
        self::assertSame(90, $preferences->retentionDays);
    }

    public function testTheLastRemainingTopicCannotBeMuted(): void
    {
        $preferences = Preferences::fromInput([
            'muted_topics' => Classifier::TOPIC_ORDER,
        ]);

        self::assertCount(count(Classifier::TOPIC_ORDER) - 1, $preferences->mutedTopics);
        self::assertNotEmpty($preferences->activeTopics());
    }

    public function testReadingTheSameArticleTwiceKeepsTheFirstTimestamp(): void
    {
        $article = self::article('zeit', 'climate', 'https://example.org/a');
        $this->store->reads->record($this->user->id, $article, $this->now);
        $this->store->reads->record($this->user->id, $article, $this->now->modify('+2 hours'));

        $reads = $this->store->reads->since($this->user->id, $this->now->modify('-1 day'));
        self::assertCount(1, $reads);
        self::assertSame('2026-08-05 12:00:00', Database::stamp($reads[0]->at));
    }

    public function testReadStateIsReportedPerUrl(): void
    {
        $this->store->reads->record($this->user->id, self::article('zeit', 'climate', 'https://example.org/a'), $this->now);

        $read = $this->store->reads->readAmong($this->user->id, ['https://example.org/a', 'https://example.org/b']);
        self::assertArrayHasKey('https://example.org/a', $read);
        self::assertArrayNotHasKey('https://example.org/b', $read);
    }

    public function testRetentionPrunesOldReadsAndZeroKeepsThem(): void
    {
        $this->store->reads->record($this->user->id, self::article('zeit', 'climate', 'https://example.org/old'), $this->now->modify('-100 days'));
        $this->store->reads->record($this->user->id, self::article('taz', 'peace', 'https://example.org/new'), $this->now);

        self::assertSame(0, $this->store->reads->prune($this->user->id, 0, $this->now));
        self::assertCount(2, $this->store->reads->since($this->user->id, $this->now->modify('-1 year')));

        self::assertSame(1, $this->store->reads->prune($this->user->id, 90, $this->now));
        self::assertCount(1, $this->store->reads->since($this->user->id, $this->now->modify('-1 year')));
    }

    public function testWatchlistIsCapped(): void
    {
        for ($i = 0; $i < Watchlist::MAX_ENTRIES; ++$i) {
            self::assertTrue($this->store->watchlist->add(
                $this->user->id,
                self::article('zeit', 'climate', "https://example.org/{$i}"),
                $this->now,
            ));
        }

        self::assertFalse($this->store->watchlist->add(
            $this->user->id,
            self::article('zeit', 'climate', 'https://example.org/one-too-many'),
            $this->now,
        ));
        self::assertSame(Watchlist::MAX_ENTRIES, $this->store->watchlist->count($this->user->id));
    }

    public function testSavingAnAlreadySavedArticleSucceedsEvenWhenFull(): void
    {
        for ($i = 0; $i < Watchlist::MAX_ENTRIES; ++$i) {
            $this->store->watchlist->add($this->user->id, self::article('zeit', 'climate', "https://example.org/{$i}"), $this->now);
        }

        self::assertTrue($this->store->watchlist->add(
            $this->user->id,
            self::article('zeit', 'climate', 'https://example.org/0'),
            $this->now,
        ));
    }

    public function testWatchlistRemoval(): void
    {
        $this->store->watchlist->add($this->user->id, self::article('zeit', 'climate', 'https://example.org/a'), $this->now);
        self::assertTrue($this->store->watchlist->contains($this->user->id, 'https://example.org/a'));

        $this->store->watchlist->remove($this->user->id, 'https://example.org/a');
        self::assertFalse($this->store->watchlist->contains($this->user->id, 'https://example.org/a'));
    }

    public function testReportsAreListedUntilResolved(): void
    {
        $this->store->reports->submit(
            $this->user->id,
            self::article('zeit', 'climate', 'https://example.org/a'),
            'topic',
            '  wrong category  ',
            $this->now,
        );

        $open = $this->store->reports->open();
        self::assertCount(1, $open);
        self::assertSame('topic', $open[0]['kind']);
        self::assertSame('wrong category', $open[0]['note']);
        self::assertSame(1, $this->store->reports->countToday($this->user->id, $this->now));

        self::assertTrue($this->store->reports->resolve((int) $open[0]['id'], $this->now));
        self::assertSame([], $this->store->reports->open());
        self::assertFalse($this->store->reports->resolve((int) $open[0]['id'], $this->now));
    }

    public function testUnknownReportKindBecomesOther(): void
    {
        $this->store->reports->submit(
            $this->user->id,
            self::article('zeit', 'climate', 'https://example.org/a'),
            'sql-injection',
            '',
            $this->now,
        );

        self::assertSame('other', $this->store->reports->open()[0]['kind']);
    }

    public function testDeletingAnAccountRemovesEverythingButAnonymisesReports(): void
    {
        $article = self::article('zeit', 'climate', 'https://example.org/a');
        $token = $this->store->sessions->start($this->user->id, $this->now);
        $this->store->reads->record($this->user->id, $article, $this->now);
        $this->store->watchlist->add($this->user->id, $article, $this->now);
        $this->store->reports->submit($this->user->id, $article, 'topic', 'note', $this->now);

        $this->store->accounts->delete($this->user->id);

        self::assertNull($this->store->accounts->find($this->user->id));
        self::assertNull($this->store->sessions->lookup($token, $this->now));
        self::assertSame([], $this->store->reads->since($this->user->id, $this->now->modify('-1 year')));
        self::assertSame(0, $this->store->watchlist->count($this->user->id));

        $reports = $this->store->reports->open();
        self::assertCount(1, $reports);
        self::assertNull($reports[0]['user_id']);
    }

    public function testExportContainsEverythingStoredAboutTheReader(): void
    {
        $article = self::article('zeit', 'climate', 'https://example.org/a');
        $this->store->reads->record($this->user->id, $article, $this->now);
        $this->store->watchlist->add($this->user->id, $article, $this->now);
        $this->store->reports->submit($this->user->id, $article, 'rating', 'note', $this->now);

        $export = $this->store->accounts->export($this->user);

        self::assertSame('https://id.example', $export['account']['issuer']);
        self::assertCount(1, $export['reads']);
        self::assertCount(1, $export['watchlist']);
        self::assertCount(1, $export['reports']);
        self::assertArrayHasKey('preferences', $export);
        self::assertArrayNotHasKey('token_hash', $export['account']);
    }

    public function testPruneAppliesEachReadersOwnRetentionSetting(): void
    {
        $other = $this->store->accounts->upsert('https://id.example', 'subject-2', null, null, $this->now);
        $this->store->accounts->savePreferences($other->id, new Preferences(retentionDays: 0));

        $old = self::article('zeit', 'climate', 'https://example.org/old');
        $this->store->reads->record($this->user->id, $old, $this->now->modify('-200 days'));
        $this->store->reads->record($other->id, $old, $this->now->modify('-200 days'));

        $this->store->prune($this->now);

        self::assertSame([], $this->store->reads->since($this->user->id, $this->now->modify('-1 year')));
        self::assertCount(1, $this->store->reads->since($other->id, $this->now->modify('-1 year')));
    }
}
