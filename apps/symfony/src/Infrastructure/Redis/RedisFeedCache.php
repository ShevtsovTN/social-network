<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Infrastructure\Redis;

use Closure;
use Redis;
use RedisException;
use SocialNetwork\Core\Application\Port\CacheUnavailable;
use SocialNetwork\Core\Application\Port\FeedCache;
use SocialNetwork\Core\Domain\Feed\FeedEntry;
use SocialNetwork\Core\Domain\Feed\FeedLimits;
use SocialNetwork\Core\Domain\Post\PostId;
use SocialNetwork\Core\Domain\User\UserId;

/**
 * Лента в ZSET: member — id поста, score — время создания в микросекундах (целое до 2^53 хранится в double точно).
 * При равных score Redis сортирует member побайтово, поэтому «от новых к старым» даёт ту же последовательность,
 * что и «created_at DESC, id DESC» в БД.
 *
 * Пустой ZSET в Redis не существует, а пустая материализованная лента должна отличаться от отсутствующей.
 * Поэтому в ленте всегда лежит служебный элемент «-» со score 0: он ниже любого настоящего score, читатели его не видят
 * (диапазон «(0») и он первым срезается при переполнении. Лента, заполненная целиком, служебного элемента не содержит.
 *
 * Вместимость берётся из core (FeedLimits::CAPACITY), здесь только её применение.
 * Каждая операция над несколькими ключами или элементами — один Lua-вызов, то есть один обмен с Redis и атомарно.
 */
final readonly class RedisFeedCache implements FeedCache
{
    // Читает страницу и продлевает жизнь ленты. Нет ключа: возвращает nil (в PHP false), это промах.
    private const string READ = <<<'LUA'
        if redis.call('EXISTS', KEYS[1]) == 0 then
            return false
        end
        redis.call('EXPIRE', KEYS[1], ARGV[3])
        return redis.call('ZRANGE', KEYS[1], '+inf', '(0', 'BYSCORE', 'REV', 'LIMIT', ARGV[1], ARGV[2])
        LUA;

    // ARGV: вместимость, TTL, затем пары score, member. Создаёт ленту или дополняет её.
    private const string MATERIALIZE = <<<'LUA'
        redis.call('ZADD', KEYS[1], 0, '-')
        for i = 3, #ARGV, 2 do
            redis.call('ZADD', KEYS[1], ARGV[i], ARGV[i + 1])
        end
        redis.call('ZREMRANGEBYRANK', KEYS[1], 0, -(tonumber(ARGV[1]) + 1))
        redis.call('EXPIRE', KEYS[1], ARGV[2])
        return 1
        LUA;

    // KEYS: ленты читателей. ARGV: вместимость, затем пары score, member. Дополняет только существующие ленты.
    private const string MERGE_IF_PRESENT = <<<'LUA'
        for k = 1, #KEYS do
            if redis.call('EXISTS', KEYS[k]) == 1 then
                for i = 2, #ARGV, 2 do
                    redis.call('ZADD', KEYS[k], ARGV[i], ARGV[i + 1])
                end
                redis.call('ZREMRANGEBYRANK', KEYS[k], 0, -(tonumber(ARGV[1]) + 1))
            end
        end
        return 1
        LUA;

    // KEYS: ленты читателей. ARGV[1]: id поста. ZREM не создаёт отсутствующий ключ.
    private const string REMOVE_POST = <<<'LUA'
        for k = 1, #KEYS do
            redis.call('ZREM', KEYS[k], ARGV[1])
        end
        return 1
        LUA;

    private LuaScript $readScript;
    private LuaScript $materializeScript;
    private LuaScript $mergeScript;
    private LuaScript $removeScript;

    public function __construct(
        private RedisConnection $connection,
        private RedisKeyspace $keyspace,
        private int $ttlSeconds,
    ) {
        $this->readScript = new LuaScript(self::READ);
        $this->materializeScript = new LuaScript(self::MATERIALIZE);
        $this->mergeScript = new LuaScript(self::MERGE_IF_PRESENT);
        $this->removeScript = new LuaScript(self::REMOVE_POST);
    }

    public function read(UserId $reader, int $offset, int $limit): ?array
    {
        /** @var false|list<string> $ids */
        $ids = $this->execute(fn (Redis $redis): mixed => $this->readScript->run(
            $redis,
            [$this->keyspace->feed($reader)],
            [(string) $offset, (string) $limit, (string) $this->ttlSeconds],
        ));

        return $ids === false ? null : array_map(PostId::fromString(...), $ids);
    }

    public function materialize(UserId $reader, array $entries): void
    {
        $this->execute(fn (Redis $redis): mixed => $this->materializeScript->run(
            $redis,
            [$this->keyspace->feed($reader)],
            [(string) FeedLimits::CAPACITY, (string) $this->ttlSeconds, ...self::scoresAndMembers($entries)],
        ));
    }

    public function mergeIfPresent(array $readers, array $entries): void
    {
        if ($readers === [] || $entries === []) {
            return;
        }

        $this->execute(fn (Redis $redis): mixed => $this->mergeScript->run(
            $redis,
            array_map($this->keyspace->feed(...), $readers),
            [(string) FeedLimits::CAPACITY, ...self::scoresAndMembers($entries)],
        ));
    }

    public function removePost(array $readers, PostId $postId): void
    {
        if ($readers === []) {
            return;
        }

        $this->execute(fn (Redis $redis): mixed => $this->removeScript->run(
            $redis,
            array_map($this->keyspace->feed(...), $readers),
            [$postId->value],
        ));
    }

    public function forget(UserId $reader): void
    {
        $this->execute(fn (Redis $redis): mixed => $redis->del($this->keyspace->feed($reader)));
    }

    /**
     * @param list<FeedEntry> $entries
     *
     * @return list<string>
     */
    private static function scoresAndMembers(array $entries): array
    {
        $arguments = [];

        foreach ($entries as $entry) {
            $arguments[] = (string) $entry->microseconds();
            $arguments[] = $entry->postId->value;
        }

        return $arguments;
    }

    /**
     * @param Closure(Redis): mixed $operation
     *
     * @throws CacheUnavailable
     */
    private function execute(Closure $operation): mixed
    {
        try {
            return $this->connection->execute($operation);
        } catch (RedisException $exception) {
            throw CacheUnavailable::because($exception);
        }
    }
}
