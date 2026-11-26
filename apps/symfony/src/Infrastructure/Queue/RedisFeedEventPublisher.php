<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Infrastructure\Queue;

use Redis;
use SocialNetwork\Core\Application\Feed\Event\FeedEvent;
use SocialNetwork\Core\Application\Port\FeedEventPublisher;
use SocialNetwork\SymfonyApp\Infrastructure\Redis\RedisConnection;
use SocialNetwork\SymfonyApp\Infrastructure\Redis\RedisKeyspace;
use Throwable;

/**
 * Публикует событие в Redis Stream. Best-effort по контракту порта: любой сбой логируется и не пробрасывается,
 * потому что запись в PostgreSQL уже зафиксирована, а ленты лечатся пересборкой.
 * Длина стрима ограничена приблизительно (MAXLEN ~): стрим не вытесняется политикой памяти Redis, так как у него нет TTL.
 */
final readonly class RedisFeedEventPublisher implements FeedEventPublisher
{
    private const int MAX_STREAM_LENGTH = 100_000;

    public function __construct(
        private RedisConnection $connection,
        private RedisKeyspace $keyspace,
        private FeedEventSerializer $serializer,
    ) {
    }

    public function publish(FeedEvent $event): void
    {
        try {
            $this->connection->execute(fn (Redis $redis): mixed => $redis->xAdd(
                $this->keyspace->eventStream(),
                '*',
                $this->serializer->toFields($event),
                self::MAX_STREAM_LENGTH,
                true,
            ));
        } catch (Throwable $exception) {
            error_log(sprintf('[symfony] feed event %s was not published: %s', $event::class, $exception->getMessage()));
        }
    }
}
