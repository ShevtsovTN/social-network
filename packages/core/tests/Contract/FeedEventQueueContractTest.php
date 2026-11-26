<?php

declare(strict_types=1);

namespace SocialNetwork\CoreContract;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\Feed\Event\FeedEvent;
use SocialNetwork\Core\Application\Feed\Event\FriendAdded;
use SocialNetwork\Core\Application\Feed\Event\FriendRemoved;
use SocialNetwork\Core\Application\Feed\Event\PostDeleted;
use SocialNetwork\Core\Application\Feed\Event\PostEdited;
use SocialNetwork\Core\Application\Feed\Event\PostPublished;

/**
 * Контракт пары портов FeedEventPublisher и FeedEventConsumer над одной очередью.
 */
abstract class FeedEventQueueContractTest extends TestCase
{
    abstract protected function createChannel(): FeedEventChannel;

    public function testAnEmptyQueueYieldsNothing(): void
    {
        $channel = $this->createChannel();

        self::assertNull($channel->consumer()->next(0));
    }

    /**
     * @return iterable<string, array{FeedEvent}>
     */
    public static function events(): iterable
    {
        yield 'post published' => [new PostPublished(FeedFixture::postId(1), FeedFixture::userId(1))];
        yield 'post edited' => [new PostEdited(FeedFixture::postId(2))];
        yield 'post deleted' => [new PostDeleted(FeedFixture::postId(3), FeedFixture::userId(2))];
        yield 'friend added' => [new FriendAdded(FeedFixture::userId(3), FeedFixture::userId(4))];
        yield 'friend removed' => [new FriendRemoved(FeedFixture::userId(5), FeedFixture::userId(6))];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('events')]
    public function testDeliversEveryKindOfEventUnchanged(FeedEvent $event): void
    {
        $channel = $this->createChannel();

        $channel->publisher()->publish($event);

        $received = $channel->consumer()->next(0);
        self::assertNotNull($received);
        self::assertEquals($event, $received->event);
        self::assertNotSame('', $received->deliveryId);
    }

    public function testDeliversEventsInThePublishingOrder(): void
    {
        $channel = $this->createChannel();
        $first = new PostPublished(FeedFixture::postId(1), FeedFixture::userId(1));
        $second = new PostEdited(FeedFixture::postId(1));
        $third = new PostDeleted(FeedFixture::postId(1), FeedFixture::userId(1));
        $channel->publisher()->publish($first);
        $channel->publisher()->publish($second);
        $channel->publisher()->publish($third);

        $delivered = [];
        while (($received = $channel->consumer()->next(0)) !== null) {
            $delivered[] = $received->event;
            $channel->consumer()->acknowledge($received);
        }

        self::assertEquals([$first, $second, $third], $delivered);
    }

    public function testAcknowledgedEventIsNotDeliveredAgain(): void
    {
        $channel = $this->createChannel();
        $channel->publisher()->publish(new PostEdited(FeedFixture::postId(1)));
        $received = $channel->consumer()->next(0);
        self::assertNotNull($received);

        $channel->consumer()->acknowledge($received);

        self::assertNull($channel->consumer()->next(0));
    }

    public function testUnacknowledgedEventIsDeliveredAgain(): void
    {
        $channel = $this->createChannel();
        $event = new PostPublished(FeedFixture::postId(1), FeedFixture::userId(1));
        $channel->publisher()->publish($event);
        $first = $channel->consumer()->next(0);
        self::assertNotNull($first);

        $again = $channel->consumer()->next(0);

        self::assertNotNull($again);
        self::assertEquals($event, $again->event);
    }

    public function testAcknowledgingOneEventLeavesTheOthersPending(): void
    {
        $channel = $this->createChannel();
        $kept = new PostEdited(FeedFixture::postId(2));
        $channel->publisher()->publish(new PostEdited(FeedFixture::postId(1)));
        $channel->publisher()->publish($kept);
        $first = $channel->consumer()->next(0);
        self::assertNotNull($first);

        $channel->consumer()->acknowledge($first);

        $next = $channel->consumer()->next(0);
        self::assertNotNull($next);
        self::assertEquals($kept, $next->event);
    }
}
