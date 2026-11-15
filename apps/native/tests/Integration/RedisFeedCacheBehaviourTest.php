<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\Port\CacheUnavailable;
use SocialNetwork\Core\Domain\Feed\FeedLimits;
use SocialNetwork\CoreContract\FeedFixture;
use SocialNetwork\NativeApp\Infrastructure\Redis\RedisConnection;
use SocialNetwork\NativeApp\Infrastructure\Redis\RedisFeedCache;
use SocialNetwork\NativeApp\Infrastructure\Redis\RedisKeyspace;
use SocialNetwork\NativeApp\Infrastructure\Redis\RedisPostCache;

/**
 * То, что contract-тесты портов проверить не могут, потому что это особенности Redis-реализации:
 * время жизни ключей, формат score, служебный элемент, недоступный Redis.
 */
final class RedisFeedCacheBehaviourTest extends TestCase
{
    use UsesIsolatedRedis;

    public function testMaterializedFeedGetsATimeToLive(): void
    {
        $cache = new RedisFeedCache($this->connection(), $this->keyspace(), 600);

        $cache->materialize(FeedFixture::userId(1), [FeedFixture::entry(1, 10)]);

        $ttl = $this->connection()->client()->ttl($this->keyspace()->feed(FeedFixture::userId(1)));
        self::assertGreaterThan(590, $ttl);
        self::assertLessThanOrEqual(600, $ttl);
    }

    public function testReadingExtendsTheTimeToLive(): void
    {
        $cache = new RedisFeedCache($this->connection(), $this->keyspace(), 600);
        $cache->materialize(FeedFixture::userId(1), [FeedFixture::entry(1, 10)]);
        $key = $this->keyspace()->feed(FeedFixture::userId(1));
        $this->connection()->client()->expire($key, 30);

        $cache->read(FeedFixture::userId(1), 0, 10);

        self::assertGreaterThan(590, $this->connection()->client()->ttl($key));
    }

    public function testMergeAndRemoveDoNotChangeTheTimeToLive(): void
    {
        $cache = new RedisFeedCache($this->connection(), $this->keyspace(), 600);
        $cache->materialize(FeedFixture::userId(1), [FeedFixture::entry(1, 10)]);
        $key = $this->keyspace()->feed(FeedFixture::userId(1));
        $this->connection()->client()->expire($key, 30);

        $cache->mergeIfPresent([FeedFixture::userId(1)], [FeedFixture::entry(2, 20)]);
        $cache->removePost([FeedFixture::userId(1)], FeedFixture::postId(1));

        self::assertLessThanOrEqual(30, $this->connection()->client()->ttl($key));
    }

    public function testScoreIsTheCreationTimeInMicroseconds(): void
    {
        $cache = new RedisFeedCache($this->connection(), $this->keyspace(), 600);
        $entry = FeedFixture::entry(1, 10, 123456);

        $cache->materialize(FeedFixture::userId(1), [$entry]);

        $score = $this->connection()->client()->zScore($this->keyspace()->feed(FeedFixture::userId(1)), $entry->postId->value);
        self::assertSame((float) $entry->microseconds(), $score);
        self::assertSame(1_800_000_010_123_456, $entry->microseconds());
    }

    public function testEmptyFeedIsKeptAliveByAHiddenMarkerThatReadersNeverSee(): void
    {
        $cache = new RedisFeedCache($this->connection(), $this->keyspace(), 600);

        $cache->materialize(FeedFixture::userId(1), []);

        $key = $this->keyspace()->feed(FeedFixture::userId(1));
        self::assertSame(1, $this->connection()->client()->exists($key));
        self::assertSame([], $cache->read(FeedFixture::userId(1), 0, 100));
    }

    public function testFullFeedDoesNotKeepTheMarker(): void
    {
        $cache = new RedisFeedCache($this->connection(), $this->keyspace(), 600);
        $entries = [];
        for ($number = 1; $number <= FeedLimits::CAPACITY; ++$number) {
            $entries[] = FeedFixture::entry($number, $number);
        }

        $cache->materialize(FeedFixture::userId(1), $entries);

        self::assertSame(FeedLimits::CAPACITY, $this->connection()->client()->zCard($this->keyspace()->feed(FeedFixture::userId(1))));
    }

    public function testRestartedRedisIsHandledByReloadingTheScripts(): void
    {
        $cache = new RedisFeedCache($this->connection(), $this->keyspace(), 600);
        $cache->materialize(FeedFixture::userId(1), [FeedFixture::entry(1, 10)]);

        $this->connection()->client()->rawCommand('SCRIPT', 'FLUSH');

        self::assertSame([FeedFixture::postId(1)->value], FeedFixture::values($cache->read(FeedFixture::userId(1), 0, 10) ?? []));
    }

    public function testCachesReportUnavailableRedisAsCacheUnavailable(): void
    {
        $down = new RedisConnection('127.0.0.1', 1, '', false, 0.2, 0.2);
        $feedCache = new RedisFeedCache($down, new RedisKeyspace('test:down'), 600);
        $postCache = new RedisPostCache($down, new RedisKeyspace('test:down'), 600);

        $reported = 0;

        foreach ([
            static fn () => $feedCache->read(FeedFixture::userId(1), 0, 10),
            static fn () => $feedCache->materialize(FeedFixture::userId(1), []),
            static fn () => $feedCache->mergeIfPresent([FeedFixture::userId(1)], [FeedFixture::entry(1)]),
            static fn () => $feedCache->removePost([FeedFixture::userId(1)], FeedFixture::postId(1)),
            static fn () => $feedCache->forget(FeedFixture::userId(1)),
            static fn () => $postCache->findMany([FeedFixture::postId(1)]),
            static fn () => $postCache->putMany([FeedFixture::post(1, 1)]),
            static fn () => $postCache->forget(FeedFixture::postId(1)),
        ] as $call) {
            try {
                $call();
                self::fail('CacheUnavailable expected.');
            } catch (CacheUnavailable) {
                ++$reported;
            }
        }

        self::assertSame(8, $reported);
    }

    public function testWrongPasswordIsReportedAsCacheUnavailable(): void
    {
        $connection = new RedisConnection((string) getenv('REDIS_HOST'), (int) getenv('REDIS_PORT'), 'wrong-password', false, 1.0, 1.0);
        $cache = new RedisFeedCache($connection, new RedisKeyspace('test:auth'), 600);

        $this->expectException(CacheUnavailable::class);

        $cache->read(FeedFixture::userId(1), 0, 10);
    }

    public function testCorruptedPostValueIsTreatedAsAMiss(): void
    {
        $cache = new RedisPostCache($this->connection(), $this->keyspace(), 600);
        $cache->putMany([FeedFixture::post(2, 1)]);
        $redis = $this->connection()->client();
        $redis->set($this->keyspace()->post(FeedFixture::postId(1)), '{"id": 5');
        $redis->set($this->keyspace()->post(FeedFixture::postId(3)), '{"id":"b0000000-0000-4000-8000-000000000003","authorId":"nope","text":"x","createdAt":"2026-01-01T00:00:00+00:00"}');

        $found = $cache->findMany([FeedFixture::postId(1), FeedFixture::postId(2), FeedFixture::postId(3)]);

        self::assertSame([FeedFixture::postId(2)->value], array_keys($found));
    }

    public function testPostBodyGetsATimeToLive(): void
    {
        $cache = new RedisPostCache($this->connection(), $this->keyspace(), 600);

        $cache->putMany([FeedFixture::post(1, 1)]);

        $ttl = $this->connection()->client()->ttl($this->keyspace()->post(FeedFixture::postId(1)));
        self::assertGreaterThan(590, $ttl);
        self::assertLessThanOrEqual(600, $ttl);
    }
}
