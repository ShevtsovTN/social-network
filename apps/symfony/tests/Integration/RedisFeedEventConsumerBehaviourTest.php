<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\Feed\Event\PostEdited;
use SocialNetwork\CoreContract\FeedFixture;
use SocialNetwork\SymfonyApp\Infrastructure\Queue\FeedEventSerializer;
use SocialNetwork\SymfonyApp\Infrastructure\Queue\RedisFeedEventConsumer;
use SocialNetwork\SymfonyApp\Infrastructure\Queue\RedisFeedEventPublisher;
use SocialNetwork\SymfonyApp\Infrastructure\Redis\RedisConnection;
use SocialNetwork\SymfonyApp\Infrastructure\Redis\RedisKeyspace;

/**
 * Особенности Redis Streams, которые contract-тесты очереди проверить не могут.
 */
final class RedisFeedEventConsumerBehaviourTest extends TestCase
{
    use UsesIsolatedRedis;

    public function testUnreadableEntriesAreDiscardedAndTheNextReadableOneIsDelivered(): void
    {
        $channel = new RedisFeedEventChannel($this->connection(), $this->keyspace());
        $stream = $this->keyspace()->eventStream();
        $redis = $this->connection()->client();
        $redis->xAdd($stream, '*', ['type' => 'unknown']);
        $redis->xAdd($stream, '*', ['garbage' => 'yes']);
        $channel->publisher()->publish(new PostEdited(FeedFixture::postId(1)));

        $received = $channel->consumer()->next(0);

        self::assertNotNull($received);
        self::assertEquals(new PostEdited(FeedFixture::postId(1)), $received->event);
        self::assertSame(1, $redis->xLen($stream), 'Unreadable entries must be removed from the stream.');
        $channel->consumer()->acknowledge($received);
        self::assertSame(0, $redis->xLen($stream));
        self::assertNull($channel->consumer()->next(0));
    }

    public function testUnacknowledgedEventIsNotRedeliveredBeforeTheIdleTimeout(): void
    {
        $channel = new RedisFeedEventChannel($this->connection(), $this->keyspace(), 60_000);
        $channel->publisher()->publish(new PostEdited(FeedFixture::postId(1)));
        self::assertNotNull($channel->consumer()->next(0));

        self::assertNull($channel->consumer()->next(0));
    }

    public function testAnotherConsumerOfTheSameGroupTakesOverAStaleEvent(): void
    {
        $serializer = new FeedEventSerializer();
        $publisher = new RedisFeedEventPublisher($this->connection(), $this->keyspace(), $serializer);
        $crashed = new RedisFeedEventConsumer($this->connection(), $this->keyspace(), $serializer, 'crashed-worker', 0);
        $healthy = new RedisFeedEventConsumer($this->connection(), $this->keyspace(), $serializer, 'healthy-worker', 0);
        $publisher->publish(new PostEdited(FeedFixture::postId(1)));
        self::assertNotNull($crashed->next(0));

        $taken = $healthy->next(0);

        self::assertNotNull($taken);
        self::assertEquals(new PostEdited(FeedFixture::postId(1)), $taken->event);
    }

    public function testBlockingWaitReturnsNullAfterTheTimeoutWithoutBreakingTheConnection(): void
    {
        // Таймаут чтения больше времени ожидания, как в воркере.
        $channel = new RedisFeedEventChannel($this->connection(readTimeoutSeconds: 5.0), $this->keyspace());

        $startedAt = microtime(true);
        $received = $channel->consumer()->next(400);
        $elapsed = microtime(true) - $startedAt;

        self::assertNull($received);
        self::assertGreaterThanOrEqual(0.35, $elapsed);
        self::assertLessThan(2.0, $elapsed);

        $channel->publisher()->publish(new PostEdited(FeedFixture::postId(1)));
        self::assertNotNull($channel->consumer()->next(0), 'Connection must still work after a blocking wait.');
    }

    public function testReadsAnEventPublishedByAnotherConnectionWithoutWaitingForTheTimeout(): void
    {
        $channel = new RedisFeedEventChannel($this->connection(readTimeoutSeconds: 5.0), $this->keyspace());
        $channel->consumer()->next(0);
        $other = new RedisConnection((string) getenv('REDIS_HOST'), (int) getenv('REDIS_PORT'), (string) getenv('REDIS_PASSWORD'), false, 1.0, 1.0);
        $serializer = new FeedEventSerializer();

        // Событие уже в стриме: блокирующее чтение возвращает его сразу, а не ждёт конца таймаута.
        (new RedisFeedEventPublisher($other, $this->keyspace(), $serializer))->publish(new PostEdited(FeedFixture::postId(7)));

        $startedAt = microtime(true);
        $received = $channel->consumer()->next(3000);

        self::assertNotNull($received);
        self::assertLessThan(1.0, microtime(true) - $startedAt);
    }

    public function testPublisherNeverThrowsWhenRedisIsDown(): void
    {
        $previous = ini_set('error_log', '/dev/null');
        $publisher = new RedisFeedEventPublisher(
            new RedisConnection('127.0.0.1', 1, '', false, 0.2, 0.2),
            new RedisKeyspace('test:down'),
            new FeedEventSerializer(),
        );

        try {
            $publisher->publish(new PostEdited(FeedFixture::postId(1)));
            $this->addToAssertionCount(1);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
    }

    public function testPublishesIntoTheStreamOfItsKeyspace(): void
    {
        $publisher = new RedisFeedEventPublisher($this->connection(), $this->keyspace(), new FeedEventSerializer());

        $publisher->publish(new PostEdited(FeedFixture::postId(1)));

        self::assertSame(1, $this->connection()->client()->xLen($this->keyspace()->eventStream()));
    }
}
