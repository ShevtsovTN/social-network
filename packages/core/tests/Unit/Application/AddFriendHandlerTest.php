<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\AddFriend\AddFriendCommand;
use SocialNetwork\Core\Application\AddFriend\AddFriendHandler;
use SocialNetwork\Core\Application\Feed\Event\FriendAdded;
use SocialNetwork\Core\Domain\Friend\InvalidFriendship;
use SocialNetwork\Core\Domain\User\InvalidUserData;
use SocialNetwork\Core\Domain\User\UserNotFound;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryFeedEventChannel;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryFeedStorage;
use SocialNetwork\CoreContract\FeedFixture;

final class AddFriendHandlerTest extends TestCase
{
    private InMemoryFeedStorage $storage;
    private InMemoryFeedEventChannel $events;
    private AddFriendHandler $handler;

    protected function setUp(): void
    {
        $this->storage = new InMemoryFeedStorage();
        $this->events = new InMemoryFeedEventChannel();
        $this->handler = new AddFriendHandler($this->storage->users(), $this->storage->friendships(), $this->events);
        FeedFixture::addUsers($this->storage, 1, 2);
    }

    public function testAddsTheFriendOneDirectionallyAndPublishesTheEvent(): void
    {
        $this->handler->handle(new AddFriendCommand(FeedFixture::userId(1), FeedFixture::userId(2)->value));

        self::assertTrue($this->storage->friendshipRepository->has(FeedFixture::userId(1), FeedFixture::userId(2)));
        self::assertFalse($this->storage->friendshipRepository->has(FeedFixture::userId(2), FeedFixture::userId(1)));
        self::assertEquals([new FriendAdded(FeedFixture::userId(1), FeedFixture::userId(2))], $this->events->pending());
    }

    public function testRepeatedAdditionIsHarmless(): void
    {
        $command = new AddFriendCommand(FeedFixture::userId(1), FeedFixture::userId(2)->value);

        $this->handler->handle($command);
        $this->handler->handle($command);

        self::assertSame([FeedFixture::userId(2)->value], $this->storage->friendshipRepository->friendIdsOf(FeedFixture::userId(1)));
    }

    public function testFriendIdIsCaseInsensitive(): void
    {
        $this->handler->handle(new AddFriendCommand(FeedFixture::userId(1), strtoupper(FeedFixture::userId(2)->value)));

        self::assertTrue($this->storage->friendshipRepository->has(FeedFixture::userId(1), FeedFixture::userId(2)));
    }

    public function testCannotAddYourself(): void
    {
        try {
            $this->handler->handle(new AddFriendCommand(FeedFixture::userId(1), FeedFixture::userId(1)->value));
            self::fail('InvalidFriendship expected.');
        } catch (InvalidFriendship $exception) {
            self::assertSame('friendId', $exception->field);
        }

        self::assertSame([], $this->storage->friendshipRepository->friendIdsOf(FeedFixture::userId(1)));
        self::assertSame([], $this->events->pending());
    }

    public function testCannotAddAnUnknownUser(): void
    {
        try {
            $this->handler->handle(new AddFriendCommand(FeedFixture::userId(1), FeedFixture::userId(99)->value));
            self::fail('UserNotFound expected.');
        } catch (UserNotFound) {
            // ожидаемо
        }

        self::assertSame([], $this->storage->friendshipRepository->friendIdsOf(FeedFixture::userId(1)));
        self::assertSame([], $this->events->pending());
    }

    public function testMalformedFriendIdIsReportedAsFriendId(): void
    {
        try {
            $this->handler->handle(new AddFriendCommand(FeedFixture::userId(1), 'not-a-uuid'));
            self::fail('InvalidUserData expected.');
        } catch (InvalidUserData $exception) {
            self::assertSame('friendId', $exception->field);
        }

        self::assertSame([], $this->events->pending());
    }
}
