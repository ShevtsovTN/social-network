<?php

declare(strict_types=1);

namespace SocialNetwork\CoreContract;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Domain\Friend\Friendship;

/**
 * Контракт порта FeedSource: что лежит в БД, в терминах «лента пользователя» и «посты автора».
 */
abstract class FeedSourceContractTest extends TestCase
{
    abstract protected function createStorage(): FeedStorage;

    public function testFeedHoldsPostsOfFriendsNewestFirst(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1, 2, 3);
        $this->befriend($storage, 1, 2, 3);
        $storage->posts()->add(FeedFixture::post(1, 2, 10));
        $storage->posts()->add(FeedFixture::post(2, 3, 30));
        $storage->posts()->add(FeedFixture::post(3, 2, 20));

        $entries = $storage->feedSource()->latestFromFriends(FeedFixture::userId(1), 100);

        self::assertSame(
            [FeedFixture::postId(2)->value, FeedFixture::postId(3)->value, FeedFixture::postId(1)->value],
            FeedFixture::postIds($entries),
        );
    }

    public function testFeedEntriesCarryCreationTimeWithMicroseconds(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1, 2);
        $this->befriend($storage, 1, 2);
        $storage->posts()->add(FeedFixture::post(1, 2, 10, 987654));

        $entries = $storage->feedSource()->latestFromFriends(FeedFixture::userId(1), 100);

        self::assertCount(1, $entries);
        self::assertSame(FeedFixture::time(10, 987654)->getTimestamp(), $entries[0]->createdAt->getTimestamp());
        self::assertSame('987654', $entries[0]->createdAt->format('u'));
        self::assertSame(FeedFixture::time(10, 987654)->getTimestamp() * 1_000_000 + 987654, $entries[0]->microseconds());
    }

    public function testFeedExcludesOwnPostsAndPostsOfNonFriends(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1, 2, 3);
        $this->befriend($storage, 1, 2);
        $storage->posts()->add(FeedFixture::post(1, 1, 10));
        $storage->posts()->add(FeedFixture::post(2, 2, 20));
        $storage->posts()->add(FeedFixture::post(3, 3, 30));

        $entries = $storage->feedSource()->latestFromFriends(FeedFixture::userId(1), 100);

        self::assertSame([FeedFixture::postId(2)->value], FeedFixture::postIds($entries));
    }

    public function testFriendshipIsOneDirectionalForTheFeed(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1, 2);
        $this->befriend($storage, 1, 2);
        $storage->posts()->add(FeedFixture::post(1, 1, 10));
        $storage->posts()->add(FeedFixture::post(2, 2, 20));

        self::assertSame([FeedFixture::postId(2)->value], FeedFixture::postIds($storage->feedSource()->latestFromFriends(FeedFixture::userId(1), 100)));
        self::assertSame([], $storage->feedSource()->latestFromFriends(FeedFixture::userId(2), 100));
    }

    public function testFeedIsLimitedToTheNewestPosts(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1, 2);
        $this->befriend($storage, 1, 2);
        foreach ([1, 2, 3, 4, 5] as $number) {
            $storage->posts()->add(FeedFixture::post($number, 2, $number));
        }

        $entries = $storage->feedSource()->latestFromFriends(FeedFixture::userId(1), 3);

        self::assertSame(
            [FeedFixture::postId(5)->value, FeedFixture::postId(4)->value, FeedFixture::postId(3)->value],
            FeedFixture::postIds($entries),
        );
    }

    public function testPostsCreatedInTheSameInstantAreOrderedByIdDescending(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1, 2, 3);
        $this->befriend($storage, 1, 2, 3);
        $storage->posts()->add(FeedFixture::post(1, 2, 10, 5));
        $storage->posts()->add(FeedFixture::post(3, 3, 10, 5));
        $storage->posts()->add(FeedFixture::post(2, 2, 10, 5));
        $storage->posts()->add(FeedFixture::post(4, 3, 10, 4));

        $entries = $storage->feedSource()->latestFromFriends(FeedFixture::userId(1), 100);

        self::assertSame(
            [FeedFixture::postId(3)->value, FeedFixture::postId(2)->value, FeedFixture::postId(1)->value, FeedFixture::postId(4)->value],
            FeedFixture::postIds($entries),
        );
    }

    public function testFeedOfAUserWithoutFriendsIsEmpty(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1, 2);
        $storage->posts()->add(FeedFixture::post(1, 2, 10));

        self::assertSame([], $storage->feedSource()->latestFromFriends(FeedFixture::userId(1), 100));
    }

    public function testRemovedFriendDisappearsFromTheFeed(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1, 2, 3);
        $this->befriend($storage, 1, 2, 3);
        $storage->posts()->add(FeedFixture::post(1, 2, 10));
        $storage->posts()->add(FeedFixture::post(2, 3, 20));

        $storage->friendships()->remove(FeedFixture::userId(1), FeedFixture::userId(2));

        self::assertSame([FeedFixture::postId(2)->value], FeedFixture::postIds($storage->feedSource()->latestFromFriends(FeedFixture::userId(1), 100)));
    }

    public function testDeletedPostDisappearsFromTheFeed(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1, 2);
        $this->befriend($storage, 1, 2);
        $storage->posts()->add(FeedFixture::post(1, 2, 10));
        $storage->posts()->add(FeedFixture::post(2, 2, 20));

        $storage->posts()->delete(FeedFixture::postId(2));

        self::assertSame([FeedFixture::postId(1)->value], FeedFixture::postIds($storage->feedSource()->latestFromFriends(FeedFixture::userId(1), 100)));
    }

    public function testLatestByAuthorReturnsOnlyThatAuthorsPostsNewestFirst(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1, 2);
        $storage->posts()->add(FeedFixture::post(1, 1, 10));
        $storage->posts()->add(FeedFixture::post(2, 2, 20));
        $storage->posts()->add(FeedFixture::post(3, 1, 30));
        $storage->posts()->add(FeedFixture::post(4, 1, 20));

        $entries = $storage->feedSource()->latestByAuthor(FeedFixture::userId(1), 100);

        self::assertSame(
            [FeedFixture::postId(3)->value, FeedFixture::postId(4)->value, FeedFixture::postId(1)->value],
            FeedFixture::postIds($entries),
        );
    }

    public function testLatestByAuthorIsLimitedAndEmptyForAnAuthorWithoutPosts(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1, 2);
        foreach ([1, 2, 3] as $number) {
            $storage->posts()->add(FeedFixture::post($number, 1, $number));
        }

        self::assertSame(
            [FeedFixture::postId(3)->value, FeedFixture::postId(2)->value],
            FeedFixture::postIds($storage->feedSource()->latestByAuthor(FeedFixture::userId(1), 2)),
        );
        self::assertSame([], $storage->feedSource()->latestByAuthor(FeedFixture::userId(2), 2));
    }

    /**
     * Пользователь $userNumber добавляет в друзья всех из $friendNumbers.
     */
    private function befriend(FeedStorage $storage, int $userNumber, int ...$friendNumbers): void
    {
        foreach ($friendNumbers as $friendNumber) {
            $storage->friendships()->add(Friendship::between(FeedFixture::userId($userNumber), FeedFixture::userId($friendNumber)));
        }
    }
}
