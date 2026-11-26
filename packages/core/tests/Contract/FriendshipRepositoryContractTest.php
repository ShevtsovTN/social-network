<?php

declare(strict_types=1);

namespace SocialNetwork\CoreContract;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Domain\Friend\Friendship;
use SocialNetwork\Core\Domain\User\UserId;

/**
 * Контракт порта FriendshipRepository.
 */
abstract class FriendshipRepositoryContractTest extends TestCase
{
    abstract protected function createStorage(): FeedStorage;

    public function testAddedFriendshipMakesUserAFollowerOfTheFriend(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1, 2);

        $storage->friendships()->add(Friendship::between(FeedFixture::userId(1), FeedFixture::userId(2)));

        self::assertSame([FeedFixture::userId(1)->value], self::values($storage->friendships()->followerIdsOf(FeedFixture::userId(2), 10)));
    }

    public function testFriendshipIsOneDirectional(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1, 2);

        $storage->friendships()->add(Friendship::between(FeedFixture::userId(1), FeedFixture::userId(2)));

        self::assertSame([], $storage->friendships()->followerIdsOf(FeedFixture::userId(1), 10));
    }

    public function testAddingTheSameFriendshipTwiceIsHarmless(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1, 2);
        $friendship = Friendship::between(FeedFixture::userId(1), FeedFixture::userId(2));

        $storage->friendships()->add($friendship);
        $storage->friendships()->add($friendship);

        self::assertCount(1, $storage->friendships()->followerIdsOf(FeedFixture::userId(2), 10));
    }

    public function testRemoveDeletesOnlyThatDirection(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1, 2, 3);
        $storage->friendships()->add(Friendship::between(FeedFixture::userId(1), FeedFixture::userId(2)));
        $storage->friendships()->add(Friendship::between(FeedFixture::userId(2), FeedFixture::userId(1)));
        $storage->friendships()->add(Friendship::between(FeedFixture::userId(3), FeedFixture::userId(2)));

        $storage->friendships()->remove(FeedFixture::userId(1), FeedFixture::userId(2));

        self::assertSame([FeedFixture::userId(3)->value], self::values($storage->friendships()->followerIdsOf(FeedFixture::userId(2), 10)));
        self::assertSame([FeedFixture::userId(2)->value], self::values($storage->friendships()->followerIdsOf(FeedFixture::userId(1), 10)));
    }

    public function testRemovingAMissingFriendshipDoesNothing(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1, 2);

        $storage->friendships()->remove(FeedFixture::userId(1), FeedFixture::userId(2));

        self::assertSame([], $storage->friendships()->followerIdsOf(FeedFixture::userId(2), 10));
    }

    public function testUnknownAuthorHasNoFollowers(): void
    {
        $storage = $this->createStorage();

        self::assertSame([], $storage->friendships()->followerIdsOf(FeedFixture::userId(99), 10));
    }

    public function testFollowersAreSortedByIdAndLimited(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1, 2, 3, 4, 5);
        // Подписчики пользователя 5 добавлены в произвольном порядке; пользователь 5 сам подписан на пользователя 1.
        foreach ([4, 2, 1, 3] as $follower) {
            $storage->friendships()->add(Friendship::between(FeedFixture::userId($follower), FeedFixture::userId(5)));
        }
        $storage->friendships()->add(Friendship::between(FeedFixture::userId(5), FeedFixture::userId(1)));

        $followers = $storage->friendships()->followerIdsOf(FeedFixture::userId(5), 3);

        self::assertSame(
            [FeedFixture::userId(1)->value, FeedFixture::userId(2)->value, FeedFixture::userId(3)->value],
            self::values($followers),
        );
    }

    public function testFollowersCanBeReadInPagesAfterTheLastId(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1, 2, 3, 4, 5, 6);
        foreach ([1, 2, 3, 4, 5] as $follower) {
            $storage->friendships()->add(Friendship::between(FeedFixture::userId($follower), FeedFixture::userId(6)));
        }

        $firstPage = $storage->friendships()->followerIdsOf(FeedFixture::userId(6), 2);
        $secondPage = $storage->friendships()->followerIdsOf(FeedFixture::userId(6), 2, $firstPage[1]);
        $thirdPage = $storage->friendships()->followerIdsOf(FeedFixture::userId(6), 2, $secondPage[1]);
        $afterTheEnd = $storage->friendships()->followerIdsOf(FeedFixture::userId(6), 2, $thirdPage[0]);

        self::assertSame([1, 2], self::numbers($firstPage));
        self::assertSame([3, 4], self::numbers($secondPage));
        self::assertSame([5], self::numbers($thirdPage));
        self::assertSame([], $afterTheEnd);
    }

    /**
     * @param list<UserId> $ids
     *
     * @return list<string>
     */
    private static function values(array $ids): array
    {
        return array_map(static fn (UserId $id): string => $id->value, $ids);
    }

    /**
     * @param list<UserId> $ids
     *
     * @return list<int>
     */
    private static function numbers(array $ids): array
    {
        return array_map(static fn (UserId $id): int => (int) substr($id->value, -12), $ids);
    }
}
