<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Infrastructure\Queue;

use Redis;
use RedisException;
use SocialNetwork\Core\Application\Feed\Event\ReceivedEvent;
use SocialNetwork\Core\Application\Port\FeedEventConsumer;
use SocialNetwork\NativeApp\Infrastructure\Redis\RedisConnection;
use SocialNetwork\NativeApp\Infrastructure\Redis\RedisKeyspace;

/**
 * Потребитель Redis Stream через consumer group (at-least-once).
 *
 * - Группа создаётся лениво с начала стрима (id «0»): события, опубликованные до первого запуска воркера, не теряются.
 * - Сначала забираются зависшие события (XAUTOCLAIM): доставленные, но не подтверждённые дольше $redeliverAfterMilliseconds
 *   (воркер упал или обработка завершилась ошибкой). Потом читаются новые (XREADGROUP).
 * - Подтверждённое событие удаляется из стрима (XACK и XDEL).
 * - Нечитаемые события (неизвестный тип, повреждённые поля) подтверждаются и отбрасываются.
 * - Блокирующее ожидание: таймаут чтения у RedisConnection должен быть больше $waitMilliseconds, иначе phpredis оборвёт соединение.
 * Ошибки Redis пробрасываются как RedisException: воркер падает и перезапускается.
 */
final class RedisFeedEventConsumer implements FeedEventConsumer
{
    private const string GROUP = 'feed-workers';

    private bool $groupReady = false;

    public function __construct(
        private readonly RedisConnection $connection,
        private readonly RedisKeyspace $keyspace,
        private readonly FeedEventSerializer $serializer,
        private readonly string $consumerName,
        private readonly int $redeliverAfterMilliseconds,
    ) {
    }

    public function next(int $waitMilliseconds): ?ReceivedEvent
    {
        $this->ensureGroup();

        while (true) {
            $delivery = $this->claimStale() ?? $this->readNew($waitMilliseconds);

            if ($delivery === null) {
                return null;
            }

            [$id, $fields] = $delivery;
            $event = $this->serializer->fromFields($fields);

            if ($event !== null) {
                return new ReceivedEvent($event, $id);
            }

            $this->remove($id);
        }
    }

    public function acknowledge(ReceivedEvent $received): void
    {
        $this->remove($received->deliveryId);
    }

    private function ensureGroup(): void
    {
        if ($this->groupReady) {
            return;
        }

        $redis = $this->connection->client();
        $redis->clearLastError();
        $redis->xGroup('CREATE', $this->keyspace->eventStream(), self::GROUP, '0', true);
        $error = $redis->getLastError();
        $redis->clearLastError();

        // BUSYGROUP: группа уже есть, это нормально.
        if ($error !== null && !str_starts_with($error, 'BUSYGROUP')) {
            throw new RedisException($error);
        }

        $this->groupReady = true;
    }

    /**
     * @return array{string, array<array-key, mixed>}|null
     */
    private function claimStale(): ?array
    {
        /** @var array{0?: string, 1?: array<string, array<array-key, mixed>>} $result */
        $result = $this->connection->execute(fn (Redis $redis): mixed => $redis->xAutoClaim(
            $this->keyspace->eventStream(),
            self::GROUP,
            $this->consumerName,
            $this->redeliverAfterMilliseconds,
            '0-0',
            1,
        ));

        return self::firstEntry($result[1] ?? []);
    }

    /**
     * @return array{string, array<array-key, mixed>}|null
     */
    private function readNew(int $waitMilliseconds): ?array
    {
        /** @var array<string, array<string, array<array-key, mixed>>>|false $result */
        $result = $this->connection->execute(fn (Redis $redis): mixed => $redis->xReadGroup(
            self::GROUP,
            $this->consumerName,
            [$this->keyspace->eventStream() => '>'],
            1,
            $waitMilliseconds > 0 ? $waitMilliseconds : null,
        ));

        if (!is_array($result)) {
            return null;
        }

        return self::firstEntry($result[$this->keyspace->eventStream()] ?? []);
    }

    private function remove(string $id): void
    {
        $this->connection->execute(function (Redis $redis) use ($id): void {
            $redis->xAck($this->keyspace->eventStream(), self::GROUP, [$id]);
            $redis->xDel($this->keyspace->eventStream(), [$id]);
        });
    }

    /**
     * @param array<array-key, array<array-key, mixed>> $entries id => поля
     *
     * @return array{string, array<array-key, mixed>}|null
     */
    private static function firstEntry(array $entries): ?array
    {
        $id = array_key_first($entries);

        return $id === null ? null : [(string) $id, $entries[$id]];
    }
}
