<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\ApplyFeedEvent\ApplyFeedEvent;
use SocialNetwork\Core\Application\Feed\Event\FeedEvent;
use SocialNetwork\Core\Application\Feed\Event\PostEdited;
use SocialNetwork\Core\Application\ProcessNextFeedEvent\ProcessNextFeedEventCommand;
use SocialNetwork\Core\Application\ProcessNextFeedEvent\ProcessNextFeedEventHandler;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryFeedEventChannel;
use SocialNetwork\CoreContract\FeedFixture;

final class ProcessNextFeedEventHandlerTest extends TestCase
{
    public function testReportsAnEmptyQueue(): void
    {
        $applier = new class implements ApplyFeedEvent {
            /** @var list<FeedEvent> */
            public array $applied = [];

            public function handle(FeedEvent $event): void
            {
                $this->applied[] = $event;
            }
        };
        $handler = new ProcessNextFeedEventHandler(new InMemoryFeedEventChannel(), $applier);

        self::assertFalse($handler->handle(new ProcessNextFeedEventCommand(0)));
        self::assertSame([], $applier->applied);
    }

    public function testAppliesTheNextEventAndAcknowledgesIt(): void
    {
        $channel = new InMemoryFeedEventChannel();
        $first = new PostEdited(FeedFixture::postId(1));
        $second = new PostEdited(FeedFixture::postId(2));
        $channel->publish($first);
        $channel->publish($second);
        $applier = new class implements ApplyFeedEvent {
            /** @var list<FeedEvent> */
            public array $applied = [];

            public function handle(FeedEvent $event): void
            {
                $this->applied[] = $event;
            }
        };
        $handler = new ProcessNextFeedEventHandler($channel, $applier);

        self::assertTrue($handler->handle(new ProcessNextFeedEventCommand(0)));
        self::assertTrue($handler->handle(new ProcessNextFeedEventCommand(0)));
        self::assertFalse($handler->handle(new ProcessNextFeedEventCommand(0)));

        self::assertEquals([$first, $second], $applier->applied);
        self::assertSame([], $channel->pending());
    }

    public function testFailedEventIsNotAcknowledgedAndComesAgain(): void
    {
        $channel = new InMemoryFeedEventChannel();
        $event = new PostEdited(FeedFixture::postId(1));
        $channel->publish($event);
        $failing = new class implements ApplyFeedEvent {
            public function handle(FeedEvent $event): void
            {
                throw new \RuntimeException('cache is down');
            }
        };

        try {
            (new ProcessNextFeedEventHandler($channel, $failing))->handle(new ProcessNextFeedEventCommand(0));
            self::fail('The failure must reach the caller.');
        } catch (\RuntimeException $exception) {
            self::assertSame('cache is down', $exception->getMessage());
        }

        self::assertEquals([$event], $channel->pending());
    }
}
