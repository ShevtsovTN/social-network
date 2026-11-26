<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Tests\Integration;

use SocialNetwork\Core\Application\Port\FeedEventConsumer;
use SocialNetwork\Core\Application\Port\FeedEventPublisher;
use SocialNetwork\CoreContract\FeedEventChannel;
use SocialNetwork\NativeApp\Infrastructure\Queue\FeedEventSerializer;
use SocialNetwork\NativeApp\Infrastructure\Queue\RedisFeedEventConsumer;
use SocialNetwork\NativeApp\Infrastructure\Queue\RedisFeedEventPublisher;
use SocialNetwork\NativeApp\Infrastructure\Redis\RedisConnection;
use SocialNetwork\NativeApp\Infrastructure\Redis\RedisKeyspace;

/**
 * Очередь событий на Redis Streams для contract-тестов: издатель и потребитель над одним стримом,
 * неподтверждённое событие доставляется повторно сразу (время ожидания 0).
 */
final readonly class RedisFeedEventChannel implements FeedEventChannel
{
    private RedisFeedEventPublisher $publisher;
    private RedisFeedEventConsumer $consumer;

    public function __construct(RedisConnection $connection, RedisKeyspace $keyspace, int $redeliverAfterMilliseconds = 0)
    {
        $serializer = new FeedEventSerializer();
        $this->publisher = new RedisFeedEventPublisher($connection, $keyspace, $serializer);
        $this->consumer = new RedisFeedEventConsumer($connection, $keyspace, $serializer, 'test-consumer', $redeliverAfterMilliseconds);
    }

    public function publisher(): FeedEventPublisher
    {
        return $this->publisher;
    }

    public function consumer(): FeedEventConsumer
    {
        return $this->consumer;
    }
}
