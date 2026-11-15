<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\ApplyFeedEvent\ApplyFeedEventHandler;
use SocialNetwork\Core\Application\Feed\Event\FeedEvent;
use SocialNetwork\Core\Application\Feed\Event\FriendAdded;
use SocialNetwork\Core\Application\Feed\Event\FriendRemoved;
use SocialNetwork\Core\Application\Feed\Event\PostDeleted;
use SocialNetwork\Core\Application\Feed\Event\PostEdited;
use SocialNetwork\Core\Application\Feed\Event\PostPublished;
use SocialNetwork\Core\Domain\Friend\Friendship;
use SocialNetwork\Core\Domain\Post\PostText;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryFeedCache;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryFeedStorage;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryPostCache;
use SocialNetwork\CoreContract\FeedFixture;

final class ApplyFeedEventHandlerTest extends TestCase
{
    private const int AUTHOR = 1;
    private const int FOLLOWER = 2;
    private const int OTHER_FOLLOWER = 3;

    private InMemoryFeedStorage $storage;
    private InMemoryFeedCache $feedCache;
    private InMemoryPostCache $postCache;
    private ApplyFeedEventHandler $handler;

    protected function setUp(): void
    {
        $this->storage = new InMemoryFeedStorage();
        $this->feedCache = new InMemoryFeedCache();
        $this->postCache = new InMemoryPostCache();
        $this->handler = new ApplyFeedEventHandler(
            $this->storage->posts(),
            $this->storage->friendships(),
            $this->storage->feedSource(),
            $this->feedCache,
            $this->postCache,
        );
    }

    public function testNewPostGoesIntoExistingFeedsOfFollowersOnly(): void
    {
        $this->follow(self::FOLLOWER, self::AUTHOR);
        $this->follow(self::OTHER_FOLLOWER, self::AUTHOR);
        $this->feedCache->materialize(FeedFixture::userId(self::FOLLOWER), [FeedFixture::entry(10, 10)]);
        $this->storage->posts()->add(FeedFixture::post(1, self::AUTHOR, 20));

        $this->handler->handle(new PostPublished(FeedFixture::postId(1), FeedFixture::userId(self::AUTHOR)));

        self::assertSame($this->postIds(1, 10), $this->feed(self::FOLLOWER));
        self::assertFalse($this->feedCache->has(FeedFixture::userId(self::OTHER_FOLLOWER)));
    }

    public function testNewPostDoesNotGoIntoFeedsOfNonFollowersOrTheAuthor(): void
    {
        $this->feedCache->materialize(FeedFixture::userId(self::FOLLOWER), []);
        $this->feedCache->materialize(FeedFixture::userId(self::AUTHOR), []);
        $this->storage->posts()->add(FeedFixture::post(1, self::AUTHOR, 20));

        $this->handler->handle(new PostPublished(FeedFixture::postId(1), FeedFixture::userId(self::AUTHOR)));

        self::assertSame([], $this->feed(self::FOLLOWER));
        self::assertSame([], $this->feed(self::AUTHOR));
    }

    public function testNewPostBodyIsCachedForReaders(): void
    {
        $post = FeedFixture::post(1, self::AUTHOR, 20, 0, 'Свежий пост');
        $this->storage->posts()->add($post);

        $this->handler->handle(new PostPublished($post->id, $post->authorId));

        self::assertEquals([$post->id->value => $post], $this->postCache->findMany([$post->id]));
    }

    public function testNewPostReachesEveryFollowerAcrossSeveralBatches(): void
    {
        $followers = 1203;
        for ($number = 1; $number <= $followers; ++$number) {
            $this->follow(100 + $number, self::AUTHOR);
        }
        // Лента есть только у каждого второго подписчика.
        for ($number = 2; $number <= $followers; $number += 2) {
            $this->feedCache->materialize(FeedFixture::userId(100 + $number), []);
        }
        $this->storage->posts()->add(FeedFixture::post(1, self::AUTHOR, 20));

        $this->handler->handle(new PostPublished(FeedFixture::postId(1), FeedFixture::userId(self::AUTHOR)));

        for ($number = 1; $number <= $followers; ++$number) {
            $reader = FeedFixture::userId(100 + $number);
            if ($number % 2 === 0) {
                self::assertSame($this->postIds(1), $this->feed(100 + $number), "Follower {$number} must get the post.");
            } else {
                self::assertFalse($this->feedCache->has($reader), "Follower {$number} has no feed and must not get one.");
            }
        }
    }

    public function testPublishingAPostThatIsAlreadyGoneDoesNothing(): void
    {
        $this->follow(self::FOLLOWER, self::AUTHOR);
        $this->feedCache->materialize(FeedFixture::userId(self::FOLLOWER), []);

        $this->handler->handle(new PostPublished(FeedFixture::postId(1), FeedFixture::userId(self::AUTHOR)));

        self::assertSame([], $this->feed(self::FOLLOWER));
        self::assertFalse($this->postCache->has(FeedFixture::postId(1)));
    }

    public function testApplyingTheSameEventTwiceChangesNothing(): void
    {
        $this->follow(self::FOLLOWER, self::AUTHOR);
        $this->feedCache->materialize(FeedFixture::userId(self::FOLLOWER), []);
        $this->storage->posts()->add(FeedFixture::post(1, self::AUTHOR, 20));
        $event = new PostPublished(FeedFixture::postId(1), FeedFixture::userId(self::AUTHOR));

        $this->handler->handle($event);
        $this->handler->handle($event);

        self::assertSame($this->postIds(1), $this->feed(self::FOLLOWER));
    }

    public function testEditedPostBodyIsRefreshedFromTheDatabase(): void
    {
        $post = FeedFixture::post(1, self::AUTHOR, 20, 0, 'Старый текст');
        $this->storage->posts()->add($post);
        $this->postCache->putMany([$post]);
        $this->storage->posts()->update($post->edit(PostText::fromString('Новый текст')));

        $this->handler->handle(new PostEdited(FeedFixture::postId(1)));

        self::assertSame('Новый текст', $this->postCache->findMany([FeedFixture::postId(1)])[FeedFixture::postId(1)->value]->text->value);
    }

    public function testEditedPostThatIsAlreadyGoneIsDroppedFromTheCache(): void
    {
        $this->postCache->putMany([FeedFixture::post(1, self::AUTHOR, 20)]);

        $this->handler->handle(new PostEdited(FeedFixture::postId(1)));

        self::assertFalse($this->postCache->has(FeedFixture::postId(1)));
    }

    public function testDeletedPostLeavesFeedsOfFollowersAndTheBodyCache(): void
    {
        $this->follow(self::FOLLOWER, self::AUTHOR);
        $this->follow(self::OTHER_FOLLOWER, self::AUTHOR);
        $this->feedCache->materialize(FeedFixture::userId(self::FOLLOWER), [FeedFixture::entry(1, 10), FeedFixture::entry(2, 20)]);
        $this->postCache->putMany([FeedFixture::post(1, self::AUTHOR, 10)]);

        $this->handler->handle(new PostDeleted(FeedFixture::postId(1), FeedFixture::userId(self::AUTHOR)));

        self::assertSame($this->postIds(2), $this->feed(self::FOLLOWER));
        self::assertFalse($this->postCache->has(FeedFixture::postId(1)));
        self::assertFalse($this->feedCache->has(FeedFixture::userId(self::OTHER_FOLLOWER)));
    }

    public function testNewFriendsExistingPostsAreBackfilledIntoAnExistingFeed(): void
    {
        $this->feedCache->materialize(FeedFixture::userId(self::FOLLOWER), [FeedFixture::entry(10, 100)]);
        $this->storage->posts()->add(FeedFixture::post(1, self::AUTHOR, 10));
        $this->storage->posts()->add(FeedFixture::post(2, self::AUTHOR, 20));
        $this->storage->posts()->add(FeedFixture::post(3, self::OTHER_FOLLOWER, 30));

        $this->handler->handle(new FriendAdded(FeedFixture::userId(self::FOLLOWER), FeedFixture::userId(self::AUTHOR)));

        self::assertSame($this->postIds(10, 2, 1), $this->feed(self::FOLLOWER));
    }

    public function testBackfillDoesNotCreateAFeed(): void
    {
        $this->storage->posts()->add(FeedFixture::post(1, self::AUTHOR, 10));

        $this->handler->handle(new FriendAdded(FeedFixture::userId(self::FOLLOWER), FeedFixture::userId(self::AUTHOR)));

        self::assertFalse($this->feedCache->has(FeedFixture::userId(self::FOLLOWER)));
    }

    public function testBackfillOfAFriendWithoutPostsChangesNothing(): void
    {
        $this->feedCache->materialize(FeedFixture::userId(self::FOLLOWER), [FeedFixture::entry(10, 100)]);

        $this->handler->handle(new FriendAdded(FeedFixture::userId(self::FOLLOWER), FeedFixture::userId(self::AUTHOR)));

        self::assertSame($this->postIds(10), $this->feed(self::FOLLOWER));
    }

    public function testRemovedFriendResetsTheFeed(): void
    {
        $this->feedCache->materialize(FeedFixture::userId(self::FOLLOWER), [FeedFixture::entry(1, 10)]);
        $this->feedCache->materialize(FeedFixture::userId(self::OTHER_FOLLOWER), [FeedFixture::entry(1, 10)]);

        $this->handler->handle(new FriendRemoved(FeedFixture::userId(self::FOLLOWER), FeedFixture::userId(self::AUTHOR)));

        self::assertFalse($this->feedCache->has(FeedFixture::userId(self::FOLLOWER)));
        self::assertTrue($this->feedCache->has(FeedFixture::userId(self::OTHER_FOLLOWER)));
    }

    public function testUnknownEventIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);

        $this->handler->handle(new class implements FeedEvent {
        });
    }

    private function follow(int $followerNumber, int $authorNumber): void
    {
        $this->storage->friendships()->add(Friendship::between(FeedFixture::userId($followerNumber), FeedFixture::userId($authorNumber)));
    }

    /**
     * @return list<string>
     */
    private function postIds(int ...$numbers): array
    {
        return array_map(static fn (int $number): string => FeedFixture::postId($number)->value, $numbers);
    }

    /**
     * @return list<string>
     */
    private function feed(int $readerNumber): array
    {
        $ids = $this->feedCache->read(FeedFixture::userId($readerNumber), 0, 5000);
        self::assertNotNull($ids, 'Feed must exist.');

        return FeedFixture::values($ids);
    }
}
