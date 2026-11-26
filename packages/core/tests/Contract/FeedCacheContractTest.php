<?php

declare(strict_types=1);

namespace SocialNetwork\CoreContract;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\Port\FeedCache;
use SocialNetwork\Core\Domain\Feed\FeedEntry;
use SocialNetwork\Core\Domain\Feed\FeedLimits;

/**
 * Контракт порта FeedCache. Реализация (in-memory, Redis в native, Redis в symfony) возвращает из createFeedCache()
 * пустой кэш: для Redis это уникальный префикс ключей на каждый тест.
 */
abstract class FeedCacheContractTest extends TestCase
{
    abstract protected function createFeedCache(): FeedCache;

    public function testReportsAMissForAReaderWithoutFeed(): void
    {
        $cache = $this->createFeedCache();

        self::assertNull($cache->read(FeedFixture::userId(1), 0, 10));
    }

    public function testMaterializedEmptyFeedIsAHitNotAMiss(): void
    {
        $cache = $this->createFeedCache();

        $cache->materialize(FeedFixture::userId(1), []);

        self::assertSame([], $cache->read(FeedFixture::userId(1), 0, 10));
    }

    public function testReadsNewestFirstWhateverTheInsertionOrder(): void
    {
        $cache = $this->createFeedCache();

        $cache->materialize(FeedFixture::userId(1), [FeedFixture::entry(2, 20), FeedFixture::entry(3, 30), FeedFixture::entry(1, 10)]);

        self::assertSame($this->postIds(3, 2, 1), $this->readAll($cache, 1));
    }

    public function testEntriesCreatedInTheSameInstantAreOrderedByIdDescending(): void
    {
        $cache = $this->createFeedCache();

        $cache->materialize(FeedFixture::userId(1), [FeedFixture::entry(2, 10, 5), FeedFixture::entry(3, 10, 5), FeedFixture::entry(1, 10, 5)]);

        self::assertSame($this->postIds(3, 2, 1), $this->readAll($cache, 1));
    }

    public function testMicrosecondsDecideTheOrder(): void
    {
        $cache = $this->createFeedCache();

        $cache->materialize(FeedFixture::userId(1), [FeedFixture::entry(1, 10, 2), FeedFixture::entry(2, 10, 1), FeedFixture::entry(3, 10, 3)]);

        self::assertSame($this->postIds(3, 1, 2), $this->readAll($cache, 1));
    }

    public function testReadsPages(): void
    {
        $cache = $this->createFeedCache();
        $cache->materialize(FeedFixture::userId(1), $this->entries(1, 5));

        self::assertSame($this->postIds(5, 4), FeedFixture::values($cache->read(FeedFixture::userId(1), 0, 2) ?? []));
        self::assertSame($this->postIds(3, 2), FeedFixture::values($cache->read(FeedFixture::userId(1), 2, 2) ?? []));
        self::assertSame($this->postIds(1), FeedFixture::values($cache->read(FeedFixture::userId(1), 4, 2) ?? []));
        self::assertSame([], $cache->read(FeedFixture::userId(1), 5, 2));
        self::assertSame([], $cache->read(FeedFixture::userId(1), FeedLimits::CAPACITY, 100));
    }

    public function testReadingDoesNotChangeTheFeed(): void
    {
        $cache = $this->createFeedCache();
        $cache->materialize(FeedFixture::userId(1), $this->entries(1, 3));

        $cache->read(FeedFixture::userId(1), 0, 1);
        $cache->read(FeedFixture::userId(1), 1, 1);

        self::assertSame($this->postIds(3, 2, 1), $this->readAll($cache, 1));
    }

    public function testKeepsOnlyTheNewestEntriesUpToCapacity(): void
    {
        $cache = $this->createFeedCache();

        $cache->materialize(FeedFixture::userId(1), $this->entries(1, FeedLimits::CAPACITY + 5));

        $ids = $this->readAll($cache, 1);
        self::assertCount(FeedLimits::CAPACITY, $ids);
        self::assertSame(FeedFixture::postId(FeedLimits::CAPACITY + 5)->value, $ids[0]);
        self::assertSame(FeedFixture::postId(6)->value, $ids[FeedLimits::CAPACITY - 1]);
    }

    public function testMaterializeMergesIntoAnExistingFeedWithoutDuplicates(): void
    {
        $cache = $this->createFeedCache();
        $cache->materialize(FeedFixture::userId(1), [FeedFixture::entry(1, 10), FeedFixture::entry(2, 20)]);

        $cache->materialize(FeedFixture::userId(1), [FeedFixture::entry(2, 20), FeedFixture::entry(3, 30)]);

        self::assertSame($this->postIds(3, 2, 1), $this->readAll($cache, 1));
    }

    public function testMergeIfPresentAddsOnlyToExistingFeeds(): void
    {
        $cache = $this->createFeedCache();
        $cache->materialize(FeedFixture::userId(1), [FeedFixture::entry(1, 10)]);
        $cache->materialize(FeedFixture::userId(2), []);

        $cache->mergeIfPresent(
            [FeedFixture::userId(1), FeedFixture::userId(2), FeedFixture::userId(3)],
            [FeedFixture::entry(2, 20)],
        );

        self::assertSame($this->postIds(2, 1), $this->readAll($cache, 1));
        self::assertSame($this->postIds(2), $this->readAll($cache, 2));
        self::assertNull($cache->read(FeedFixture::userId(3), 0, 10));
    }

    public function testMergeIfPresentAddsSeveralEntriesToOneFeed(): void
    {
        $cache = $this->createFeedCache();
        $cache->materialize(FeedFixture::userId(1), [FeedFixture::entry(10, 100)]);

        $cache->mergeIfPresent([FeedFixture::userId(1)], [FeedFixture::entry(1, 10), FeedFixture::entry(2, 200), FeedFixture::entry(3, 50)]);

        self::assertSame($this->postIds(2, 10, 3, 1), $this->readAll($cache, 1));
    }

    public function testMergeIfPresentWithoutEntriesChangesNothing(): void
    {
        $cache = $this->createFeedCache();
        $cache->materialize(FeedFixture::userId(1), [FeedFixture::entry(1, 10)]);

        $cache->mergeIfPresent([FeedFixture::userId(1), FeedFixture::userId(2)], []);

        self::assertSame($this->postIds(1), $this->readAll($cache, 1));
        self::assertNull($cache->read(FeedFixture::userId(2), 0, 10));
    }

    public function testMergeIfPresentKeepsTheFeedWithinCapacityDroppingTheOldest(): void
    {
        $cache = $this->createFeedCache();
        $cache->materialize(FeedFixture::userId(1), $this->entries(1, FeedLimits::CAPACITY));

        $cache->mergeIfPresent([FeedFixture::userId(1)], [FeedFixture::entry(5000, 5000)]);

        $ids = $this->readAll($cache, 1);
        self::assertCount(FeedLimits::CAPACITY, $ids);
        self::assertSame(FeedFixture::postId(5000)->value, $ids[0]);
        self::assertSame(FeedFixture::postId(2)->value, $ids[FeedLimits::CAPACITY - 1]);
    }

    public function testRemovePostTakesItOutOfExistingFeedsOnly(): void
    {
        $cache = $this->createFeedCache();
        $cache->materialize(FeedFixture::userId(1), $this->entries(1, 3));
        $cache->materialize(FeedFixture::userId(2), $this->entries(1, 3));

        $cache->removePost([FeedFixture::userId(1), FeedFixture::userId(3)], FeedFixture::postId(2));

        self::assertSame($this->postIds(3, 1), $this->readAll($cache, 1));
        self::assertSame($this->postIds(3, 2, 1), $this->readAll($cache, 2));
        self::assertNull($cache->read(FeedFixture::userId(3), 0, 10));
    }

    public function testFeedStaysMaterializedAfterItsLastPostIsRemoved(): void
    {
        $cache = $this->createFeedCache();
        $cache->materialize(FeedFixture::userId(1), [FeedFixture::entry(1, 10)]);

        $cache->removePost([FeedFixture::userId(1)], FeedFixture::postId(1));

        self::assertSame([], $cache->read(FeedFixture::userId(1), 0, 10));
    }

    public function testForgetDropsTheFeedSoTheNextReadIsAMiss(): void
    {
        $cache = $this->createFeedCache();
        $cache->materialize(FeedFixture::userId(1), $this->entries(1, 3));
        $cache->materialize(FeedFixture::userId(2), $this->entries(1, 3));

        $cache->forget(FeedFixture::userId(1));

        self::assertNull($cache->read(FeedFixture::userId(1), 0, 10));
        self::assertSame($this->postIds(3, 2, 1), $this->readAll($cache, 2));
    }

    public function testForgettingAMissingFeedDoesNothing(): void
    {
        $cache = $this->createFeedCache();

        $cache->forget(FeedFixture::userId(1));

        self::assertNull($cache->read(FeedFixture::userId(1), 0, 10));
    }

    public function testFeedsOfDifferentReadersAreIndependent(): void
    {
        $cache = $this->createFeedCache();

        $cache->materialize(FeedFixture::userId(1), [FeedFixture::entry(1, 10)]);
        $cache->materialize(FeedFixture::userId(2), [FeedFixture::entry(2, 20)]);

        self::assertSame($this->postIds(1), $this->readAll($cache, 1));
        self::assertSame($this->postIds(2), $this->readAll($cache, 2));
    }

    /**
     * Записи с номерами постов от $from до $to включительно; чем больше номер, тем новее.
     *
     * @return list<FeedEntry>
     */
    private function entries(int $from, int $to): array
    {
        $entries = [];

        for ($number = $from; $number <= $to; ++$number) {
            $entries[] = FeedFixture::entry($number, $number);
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function postIds(int ...$numbers): array
    {
        return array_map(static fn (int $number): string => FeedFixture::postId($number)->value, $numbers);
    }

    /**
     * Вся лента читателя одной страницей.
     *
     * @return list<string>
     */
    private function readAll(FeedCache $cache, int $readerNumber): array
    {
        $ids = $cache->read(FeedFixture::userId($readerNumber), 0, FeedLimits::CAPACITY * 2);
        self::assertNotNull($ids, 'Feed must be materialized.');

        return FeedFixture::values($ids);
    }
}
