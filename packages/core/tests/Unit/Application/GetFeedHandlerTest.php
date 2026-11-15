<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\GetFeed\GetFeedHandler;
use SocialNetwork\Core\Application\GetFeed\GetFeedQuery;
use SocialNetwork\Core\Application\Port\FeedCache;
use SocialNetwork\Core\Application\Port\PostCache;
use SocialNetwork\Core\Domain\Feed\InvalidFeedPage;
use SocialNetwork\Core\Domain\Friend\Friendship;
use SocialNetwork\Core\Domain\Post\Post;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryFeedCache;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryFeedStorage;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryPostCache;
use SocialNetwork\Core\Tests\Unit\Fake\UnavailableFeedCache;
use SocialNetwork\Core\Tests\Unit\Fake\UnavailablePostCache;
use SocialNetwork\CoreContract\FeedFixture;

final class GetFeedHandlerTest extends TestCase
{
    private const int READER = 1;
    private const int FRIEND = 2;

    private InMemoryFeedStorage $storage;
    private InMemoryFeedCache $feedCache;
    private InMemoryPostCache $postCache;

    protected function setUp(): void
    {
        $this->storage = new InMemoryFeedStorage();
        $this->feedCache = new InMemoryFeedCache();
        $this->postCache = new InMemoryPostCache();
    }

    public function testBuildsTheFeedFromTheDatabaseOnAMissAndCachesIt(): void
    {
        $this->givenFriendWithPosts(3);

        $posts = $this->handler()->handle($this->query('0', '10'));

        self::assertSame($this->postIds(3, 2, 1), $this->ids($posts));
        self::assertSame($this->postIds(3, 2, 1), FeedFixture::values($this->feedCache->read(FeedFixture::userId(self::READER), 0, 100) ?? []));
        self::assertTrue($this->postCache->has(FeedFixture::postId(3)));
    }

    public function testReturnsPostsWithAllTheirFields(): void
    {
        $this->givenFriendWithPosts(1);

        $posts = $this->handler()->handle($this->query('0', '10'));

        self::assertEquals([FeedFixture::post(1, self::FRIEND, 1)], $posts);
    }

    public function testServesAHitFromTheCacheWithoutAskingTheDatabaseForTheFeed(): void
    {
        // Друга в БД нет, поэтому источник вернул бы пустую ленту: пост виден только из-за кэша.
        $this->storage->posts()->add(FeedFixture::post(1, self::FRIEND, 1));
        $this->feedCache->materialize(FeedFixture::userId(self::READER), [FeedFixture::entry(1, 1)]);

        $posts = $this->handler()->handle($this->query('0', '10'));

        self::assertSame($this->postIds(1), $this->ids($posts));
    }

    public function testTakesPostBodiesFromTheCacheWithoutAskingTheDatabase(): void
    {
        $post = FeedFixture::post(1, self::FRIEND, 1, 0, 'Из кэша');
        $this->postCache->putMany([$post]);
        $this->feedCache->materialize(FeedFixture::userId(self::READER), [FeedFixture::entry(1, 1)]);

        $posts = $this->handler()->handle($this->query('0', '10'));

        self::assertEquals([$post], $posts);
    }

    public function testLoadsMissingBodiesFromTheDatabaseAndCachesThem(): void
    {
        $cached = FeedFixture::post(2, self::FRIEND, 2);
        $this->postCache->putMany([$cached]);
        $this->storage->posts()->add(FeedFixture::post(1, self::FRIEND, 1));
        $this->feedCache->materialize(FeedFixture::userId(self::READER), [FeedFixture::entry(1, 1), FeedFixture::entry(2, 2)]);

        $posts = $this->handler()->handle($this->query('0', '10'));

        self::assertSame($this->postIds(2, 1), $this->ids($posts));
        self::assertTrue($this->postCache->has(FeedFixture::postId(1)));
    }

    public function testSkipsPostsThatNoLongerExist(): void
    {
        $this->storage->posts()->add(FeedFixture::post(1, self::FRIEND, 1));
        $this->feedCache->materialize(FeedFixture::userId(self::READER), [FeedFixture::entry(1, 1), FeedFixture::entry(2, 2)]);

        $posts = $this->handler()->handle($this->query('0', '10'));

        self::assertSame($this->postIds(1), $this->ids($posts));
    }

    public function testReadsPagesInOrderOnAMissAndOnAHit(): void
    {
        $this->givenFriendWithPosts(5);

        $onMiss = $this->handler()->handle($this->query('1', '2'));
        $onHit = $this->handler()->handle($this->query('1', '2'));

        self::assertSame($this->postIds(4, 3), $this->ids($onMiss));
        self::assertSame($this->postIds(4, 3), $this->ids($onHit));
    }

    public function testDefaultsToTheFirstTenPosts(): void
    {
        $this->givenFriendWithPosts(12);

        $posts = $this->handler()->handle(new GetFeedQuery(FeedFixture::userId(self::READER)));

        self::assertCount(10, $posts);
        self::assertSame(FeedFixture::postId(12)->value, $posts[0]->id->value);
    }

    public function testPageBeyondTheFeedIsEmpty(): void
    {
        $this->givenFriendWithPosts(3);

        self::assertSame([], $this->handler()->handle($this->query('3', '10')));
        self::assertSame([], $this->handler()->handle($this->query('1000', '100')));
        self::assertSame([], $this->handler()->handle($this->query('99999999999999999999999', '100')));
    }

    public function testFeedWithoutFriendsIsEmptyButStillCached(): void
    {
        $posts = $this->handler()->handle($this->query('0', '10'));

        self::assertSame([], $posts);
        self::assertTrue($this->feedCache->has(FeedFixture::userId(self::READER)));
    }

    public function testOwnPostsAreNotInTheFeed(): void
    {
        $this->givenFriendWithPosts(2);
        $this->storage->posts()->add(FeedFixture::post(9, self::READER, 9));

        $posts = $this->handler()->handle($this->query('0', '10'));

        self::assertSame($this->postIds(2, 1), $this->ids($posts));
    }

    public function testRejectsInvalidPageParameters(): void
    {
        $this->givenFriendWithPosts(1);

        try {
            $this->handler()->handle($this->query('0', '0'));
            self::fail('InvalidFeedPage expected.');
        } catch (InvalidFeedPage $exception) {
            self::assertSame('limit', $exception->field);
        }

        try {
            $this->handler()->handle($this->query('-1', '10'));
            self::fail('InvalidFeedPage expected.');
        } catch (InvalidFeedPage $exception) {
            self::assertSame('offset', $exception->field);
        }
    }

    public function testInvalidParametersDoNotTouchTheCache(): void
    {
        $this->givenFriendWithPosts(1);

        try {
            $this->handler()->handle($this->query('0', '101'));
        } catch (InvalidFeedPage) {
            // ожидаемо
        }

        self::assertFalse($this->feedCache->has(FeedFixture::userId(self::READER)));
    }

    public function testReadsStraightFromTheDatabaseWhenTheFeedCacheIsDown(): void
    {
        $this->givenFriendWithPosts(5);

        $posts = $this->handler(feedCache: new UnavailableFeedCache())->handle($this->query('1', '2'));

        self::assertSame($this->postIds(4, 3), $this->ids($posts));
    }

    public function testReadsStraightFromTheDatabaseWhenThePostCacheIsDown(): void
    {
        $this->givenFriendWithPosts(5);

        $posts = $this->handler(postCache: new UnavailablePostCache())->handle($this->query('0', '3'));

        self::assertSame($this->postIds(5, 4, 3), $this->ids($posts));
    }

    public function testReadsStraightFromTheDatabaseWhenBothCachesAreDown(): void
    {
        $this->givenFriendWithPosts(3);

        $posts = $this->handler(new UnavailableFeedCache(), new UnavailablePostCache())->handle($this->query('0', '10'));

        self::assertSame($this->postIds(3, 2, 1), $this->ids($posts));
    }

    private function handler(?FeedCache $feedCache = null, ?PostCache $postCache = null): GetFeedHandler
    {
        return new GetFeedHandler(
            $feedCache ?? $this->feedCache,
            $this->storage->feedSource(),
            $postCache ?? $this->postCache,
            $this->storage->posts(),
        );
    }

    private function query(string $offset, string $limit): GetFeedQuery
    {
        return new GetFeedQuery(FeedFixture::userId(self::READER), $offset, $limit);
    }

    /**
     * Читатель дружит с автором, у автора $count постов; чем больше номер, тем новее.
     */
    private function givenFriendWithPosts(int $count): void
    {
        $this->storage->friendships()->add(Friendship::between(FeedFixture::userId(self::READER), FeedFixture::userId(self::FRIEND)));

        for ($number = 1; $number <= $count; ++$number) {
            $this->storage->posts()->add(FeedFixture::post($number, self::FRIEND, $number));
        }
    }

    /**
     * @return list<string>
     */
    private function postIds(int ...$numbers): array
    {
        return array_map(static fn (int $number): string => FeedFixture::postId($number)->value, $numbers);
    }

    /**
     * @param list<Post> $posts
     *
     * @return list<string>
     */
    private function ids(array $posts): array
    {
        return array_map(static fn (Post $post): string => $post->id->value, $posts);
    }
}
