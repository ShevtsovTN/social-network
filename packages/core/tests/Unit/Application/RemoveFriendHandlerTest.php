<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\Feed\Event\FriendRemoved;
use SocialNetwork\Core\Application\RemoveFriend\RemoveFriendCommand;
use SocialNetwork\Core\Application\RemoveFriend\RemoveFriendHandler;
use SocialNetwork\Core\Domain\Friend\Friendship;
use SocialNetwork\Core\Domain\User\InvalidUserData;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryFeedEventChannel;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryFeedStorage;
use SocialNetwork\CoreContract\FeedFixture;

final class RemoveFriendHandlerTest extends TestCase
{
    private InMemoryFeedStorage $storage;
    private InMemoryFeedEventChannel $events;
    private RemoveFriendHandler $handler;

    protected function setUp(): void
    {
        $this->storage = new InMemoryFeedStorage();
        $this->events = new InMemoryFeedEventChannel();
        $this->handler = new RemoveFriendHandler($this->storage->friendships(), $this->events);
    }

    public function testRemovesTheFriendOnlyInThatDirectionAndPublishesTheEvent(): void
    {
        $this->storage->friendships()->add(Friendship::between(FeedFixture::userId(1), FeedFixture::userId(2)));
        $this->storage->friendships()->add(Friendship::between(FeedFixture::userId(2), FeedFixture::userId(1)));

        $this->handler->handle(new RemoveFriendCommand(FeedFixture::userId(1), FeedFixture::userId(2)->value));

        self::assertFalse($this->storage->friendshipRepository->has(FeedFixture::userId(1), FeedFixture::userId(2)));
        self::assertTrue($this->storage->friendshipRepository->has(FeedFixture::userId(2), FeedFixture::userId(1)));
        self::assertEquals([new FriendRemoved(FeedFixture::userId(1), FeedFixture::userId(2))], $this->events->pending());
    }

    public function testRemovingANonFriendIsNotAnError(): void
    {
        $this->handler->handle(new RemoveFriendCommand(FeedFixture::userId(1), FeedFixture::userId(2)->value));

        self::assertSame([], $this->storage->friendshipRepository->friendIdsOf(FeedFixture::userId(1)));
    }

    public function testMalformedFriendIdIsReportedAsFriendId(): void
    {
        try {
            $this->handler->handle(new RemoveFriendCommand(FeedFixture::userId(1), 'not-a-uuid'));
            self::fail('InvalidUserData expected.');
        } catch (InvalidUserData $exception) {
            self::assertSame('friendId', $exception->field);
        }

        self::assertSame([], $this->events->pending());
    }
}
