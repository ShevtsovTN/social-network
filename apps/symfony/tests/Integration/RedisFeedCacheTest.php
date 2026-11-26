<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Tests\Integration;

use SocialNetwork\Core\Application\Port\FeedCache;
use SocialNetwork\CoreContract\FeedCacheContractTest;
use SocialNetwork\SymfonyApp\Infrastructure\Redis\RedisFeedCache;

/**
 * Идёт на настоящий Redis, у каждого теста свой префикс ключей.
 */
final class RedisFeedCacheTest extends FeedCacheContractTest
{
    use UsesIsolatedRedis;

    protected function createFeedCache(): FeedCache
    {
        return new RedisFeedCache($this->connection(), $this->keyspace(), 3600);
    }
}
